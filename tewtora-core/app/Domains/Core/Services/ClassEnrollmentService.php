<?php

namespace App\Domains\Core\Services;

use App\Domains\Auth\Contracts\AuthDirectory;
use App\Domains\Auth\Contracts\LearnerRef;
use App\Domains\Auth\Models\Account;
use App\Domains\Core\Exceptions\AlreadyEnrolledException;
use App\Domains\Core\Exceptions\ClassFullException;
use App\Domains\Core\Exceptions\ClassNotOpenForEnrollmentException;
use App\Domains\Core\Exceptions\PaymentServiceUnavailableException;
use App\Domains\Core\Mail\ClassEnrollmentConfirmed;
use App\Domains\Core\Models\ClassEnrollment;
use App\Domains\Core\Models\TewtoraClass;
use App\Domains\Core\Repositories\ClassEnrollmentRepositoryInterface;
use App\Domains\Core\Repositories\TewtoraClassRepositoryInterface;
use App\Domains\Core\Services\Payments\PaymentServiceClient;
use App\Domains\Core\Services\Payments\PaymentStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Enrolling a learner in a Tewtora class, and the payment flow behind it.
 *
 * enroll(): reserve a seat inside a transaction (class row locked, so two
 * parents can't both take the last seat), then — outside the transaction,
 * so no lock is held across a network call — ask Payment for a Paystack
 * checkout. A free class confirms straight away with no checkout. If
 * checkout can't start, the seat is released and the request fails cleanly:
 * no half-made enrolment holds a seat nobody can pay for.
 *
 * confirmPayment(): the one place a payment turns into a seat. Reached from
 * the PaymentSucceeded event (ConfirmClassEnrollmentPayment) and from
 * verifyPayment() when the payer comes back from Paystack — whichever
 * arrives first wins, the other is a no-op (at-least-once delivery,
 * CLAUDE.md "Idempotency"). Money taken always means a seat: a payment that
 * lands after the hold expired still confirms, even if that oversubscribes
 * the class by one, because there are no refunds in v1.
 */
class ClassEnrollmentService
{
    public const PAYMENT_PURPOSE = 'class_enrollment';

    public function __construct(
        private readonly TewtoraClassRepositoryInterface $classes,
        private readonly ClassEnrollmentRepositoryInterface $enrollments,
        private readonly PaymentServiceClient $payments,
        private readonly AuthDirectory $auth,
    ) {}

    /**
     * @throws ClassNotOpenForEnrollmentException
     * @throws ClassFullException
     * @throws AlreadyEnrolledException
     * @throws PaymentServiceUnavailableException
     */
    public function enroll(TewtoraClass $class, LearnerRef $learner, Account $payer): ClassEnrollment
    {
        $enrollment = $this->reserveSeat($class, $learner, $payer);

        if ($enrollment->status === ClassEnrollment::STATUS_CONFIRMED) {
            // Only a just-created row gets here (an existing confirmed seat
            // throws AlreadyEnrolledException): a free class.
            $this->afterConfirmation($enrollment);

            return $enrollment;
        }

        if ($enrollment->checkout_url !== null) {
            return $enrollment;
        }

        return $this->startCheckout($enrollment, $class, $learner, $payer);
    }

    /**
     * When the payer returns from Paystack: asks Payment (which re-verifies
     * with Paystack if it hasn't heard yet) and confirms on success, without
     * waiting for the event to arrive.
     */
    public function verifyPayment(ClassEnrollment $enrollment): ClassEnrollment
    {
        if ($enrollment->payment_id === null || $enrollment->paid_at !== null) {
            return $enrollment;
        }

        $status = $this->payments->status($enrollment->payment_id);

        if ($status->succeeded()) {
            return $this->confirmPayment($enrollment->public_id, $status);
        }

        return $enrollment;
    }

    /**
     * Idempotent; safe to call any number of times for the same payment.
     * Returns null if the enrolment doesn't exist (a payment for another
     * service's purpose, or a stale event).
     */
    public function confirmPayment(string $enrollmentPublicId, PaymentStatus $payment): ?ClassEnrollment
    {
        $enrollment = $this->enrollments->findByPublicId($enrollmentPublicId);

        if ($enrollment === null) {
            Log::channel('core')->warning('payment for unknown class enrolment', ['enrollment_id' => $enrollmentPublicId, 'payment_id' => $payment->paymentId]);

            return null;
        }

        $newlyConfirmed = false;

        $enrollment = DB::transaction(function () use ($enrollment, $payment, &$newlyConfirmed) {
            $enrollment = $this->enrollments->lockForUpdate($enrollment);

            if ($enrollment->paid_at !== null) {
                return $enrollment; // already processed — duplicate delivery
            }

            if ($payment->amountMinor !== $enrollment->amount_minor || $payment->currencyCode !== $enrollment->currency_code) {
                // Never confirm a seat on an amount we didn't ask for. Needs a human.
                Log::channel('core')->critical('class payment amount mismatch', [
                    'enrollment_id' => $enrollment->public_id,
                    'payment_id' => $payment->paymentId,
                    'expected' => [$enrollment->amount_minor, $enrollment->currency_code],
                    'received' => [$payment->amountMinor, $payment->currencyCode],
                ]);

                return $enrollment;
            }

            $changes = [
                'paid_at' => now(),
                'payment_id' => $enrollment->payment_id ?? $payment->paymentId,
                'provider_reference' => $payment->providerReference ?? $enrollment->provider_reference,
            ];

            if ($enrollment->status === ClassEnrollment::STATUS_CANCELLED) {
                // Paid after the class was cancelled. Recorded, but no seat to give.
                Log::channel('core')->critical('payment received for a cancelled class enrolment', ['enrollment_id' => $enrollment->public_id]);

                return $this->enrollments->update($enrollment, $changes);
            }

            if ($enrollment->status === ClassEnrollment::STATUS_EXPIRED) {
                Log::channel('core')->warning('late payment confirmed after the seat hold expired', ['enrollment_id' => $enrollment->public_id]);
            }

            $newlyConfirmed = true;

            return $this->enrollments->update($enrollment, $changes + [
                'status' => ClassEnrollment::STATUS_CONFIRMED,
                'confirmed_at' => now(),
            ]);
        });

        if ($newlyConfirmed) {
            $this->afterConfirmation($enrollment);
        }

        return $enrollment;
    }

    /** Scheduled (`classes:expire-holds`): gives unpaid seats back. */
    public function expireStaleHolds(): int
    {
        return $this->enrollments->expireStaleHolds();
    }

    private function reserveSeat(TewtoraClass $class, LearnerRef $learner, Account $payer): ClassEnrollment
    {
        try {
            return DB::transaction(function () use ($class, $learner, $payer) {
                $class = $this->classes->lockForUpdate($class);

                if (! $class->isOpenForEnrollment()) {
                    throw new ClassNotOpenForEnrollmentException;
                }

                $this->enrollments->expireStaleHolds($class);

                $existing = $this->enrollments->activeFor($class, $learner->id);

                if ($existing?->status === ClassEnrollment::STATUS_CONFIRMED) {
                    throw new AlreadyEnrolledException;
                }

                if ($existing !== null) {
                    return $existing; // same learner retrying — reuse the live hold and checkout
                }

                if (! $class->isUncapped() && $this->enrollments->seatsTaken($class) >= $class->capacity) {
                    throw new ClassFullException;
                }

                $free = $class->isFree();

                return $this->enrollments->create([
                    'tewtora_class_id' => $class->id,
                    'learner_profile_id' => $learner->id,
                    'payer_account_id' => $payer->id,
                    'status' => $free ? ClassEnrollment::STATUS_CONFIRMED : ClassEnrollment::STATUS_PENDING,
                    'amount_minor' => $class->price_minor,
                    'currency_code' => $class->currency_code,
                    'hold_expires_at' => $free ? null : now()->addMinutes((int) config('classes.seat_hold_minutes')),
                    'confirmed_at' => $free ? now() : null,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous requests for the same learner: the other one won.
            $existing = $this->enrollments->activeFor($class, $learner->id) ?? throw new ClassFullException;

            if ($existing->status === ClassEnrollment::STATUS_CONFIRMED) {
                throw new AlreadyEnrolledException;
            }

            return $existing;
        }
    }

    private function startCheckout(ClassEnrollment $enrollment, TewtoraClass $class, LearnerRef $learner, Account $payer): ClassEnrollment
    {
        try {
            $checkout = $this->payments->startCheckout(
                purpose: self::PAYMENT_PURPOSE,
                purposeRef: $enrollment->public_id,
                payerAccountId: $payer->id,
                payerEmail: (string) $payer->email,
                amountMinor: $enrollment->amount_minor,
                currencyCode: $enrollment->currency_code,
                callbackUrl: rtrim(config('app.frontend_url'), '/')."/classes/{$class->public_id}/enrollments/{$enrollment->public_id}/complete",
                description: "{$class->title} — {$learner->fullName}",
            );
        } catch (PaymentServiceUnavailableException $e) {
            // Give the seat back rather than hold it for a checkout that never started.
            $this->enrollments->update($enrollment, ['status' => ClassEnrollment::STATUS_EXPIRED, 'expired_at' => now()]);
            rescue(fn () => Log::channel('core')->error('class checkout could not start', [
                'enrollment_id' => $enrollment->public_id,
                'exception' => $e,
            ]), report: false);

            throw $e;
        }

        return $this->enrollments->update($enrollment, [
            'payment_id' => $checkout->paymentId,
            'checkout_url' => $checkout->checkoutUrl,
            'provider_reference' => $checkout->providerReference,
        ]);
    }

    /** Best-effort: the seat is already committed, so a mail failure can't undo it. */
    private function afterConfirmation(ClassEnrollment $enrollment): void
    {
        try {
            Log::channel('core')->info('class enrolment confirmed', ['enrollment_id' => $enrollment->public_id]);

            $email = $this->auth->accountEmail($enrollment->payer_account_id);

            if ($email !== null) {
                Mail::to($email)->queue(new ClassEnrollmentConfirmed($enrollment->load('tewtoraClass.sessions')));
            }
        } catch (Throwable $e) {
            rescue(fn () => Log::channel('core')->error('class confirmation follow-up failed', [
                'enrollment_id' => $enrollment->public_id,
                'exception' => $e,
            ]), report: false);
        }
    }
}
