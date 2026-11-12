<?php

namespace App\Domains\Core\Services;

use App\Domains\Auth\Contracts\AuthDirectory;
use App\Domains\Auth\Contracts\LearnerRef;
use App\Domains\Auth\Models\Account;
use App\Domains\Core\Exceptions\AlreadyEnrolledException;
use App\Domains\Core\Exceptions\ClassFullException;
use App\Domains\Core\Exceptions\ClassNotOpenForEnrollmentException;
use App\Domains\Core\Exceptions\PaymentDoesNotMatchEnrollmentException;
use App\Domains\Core\Exceptions\PaymentServiceUnavailableException;
use App\Domains\Core\Mail\ClassEnrollmentConfirmed;
use App\Domains\Core\Models\ClassEnrollment;
use App\Domains\Core\Models\PaymentView;
use App\Domains\Core\Models\TewtoraClass;
use App\Domains\Core\Repositories\ClassEnrollmentRepositoryInterface;
use App\Domains\Core\Repositories\TewtoraClassRepositoryInterface;
use App\Domains\Core\Services\Payments\ClassPaymentVerification;
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
 * verifyPayment(): the payer's "I've paid" check on return from Paystack.
 * Before confirming anything it checks that Payment's record of the
 * payment is for *this* enrolment (purpose + purpose_ref), so a payment
 * can never buy a seat it wasn't made for.
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
        private readonly PaymentViewService $paymentViews,
    ) {}

    /**
     * @throws ClassNotOpenForEnrollmentException
     * @throws ClassFullException
     * @throws AlreadyEnrolledException
     * @throws PaymentServiceUnavailableException
     */
    /**
     * @param  ?string  $redirectUrl  where Paystack sends the payer afterwards; already
     *                                validated against the allowlist (AllowedCheckoutRedirect).
     *                                Only used when a new checkout starts: a retry reuses the
     *                                existing checkout, and its original return URL.
     */
    public function enroll(TewtoraClass $class, LearnerRef $learner, Account $payer, ?string $redirectUrl = null): ClassEnrollment
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

        return $this->startCheckout($enrollment, $class, $learner, $payer, $redirectUrl);
    }

    /**
     * When the payer returns from Paystack: asks Payment (which re-verifies
     * with Paystack if it hasn't heard yet) and confirms on success, without
     * waiting for the event to arrive.
     */
    /**
     * @throws PaymentDoesNotMatchEnrollmentException
     * @throws PaymentServiceUnavailableException
     */
    public function verifyPayment(ClassEnrollment $enrollment): ClassPaymentVerification
    {
        if ($enrollment->paid_at !== null) {
            return new ClassPaymentVerification($enrollment, ClassPaymentVerification::PAID);
        }

        if ($enrollment->payment_id === null) {
            return new ClassPaymentVerification($enrollment, $enrollment->amount_minor === 0
                ? ClassPaymentVerification::NOT_REQUIRED
                : ClassPaymentVerification::PENDING); // checkout never started
        }

        $status = $this->payments->status($enrollment->payment_id);
        $this->assertPaymentIsFor($enrollment, $status);

        if ($status->succeeded()) {
            $enrollment = $this->confirmPayment($enrollment->public_id, $status) ?? $enrollment;

            // paid_at stays null only if confirmPayment refused it (amount mismatch, logged critical).
            return new ClassPaymentVerification($enrollment, $enrollment->paid_at !== null
                ? ClassPaymentVerification::PAID
                : ClassPaymentVerification::PENDING, $status->channel);
        }

        if ($status->failed()) {
            $this->recordPaymentFailure($enrollment->public_id, $status->paymentId);

            return new ClassPaymentVerification($enrollment, ClassPaymentVerification::FAILED);
        }

        return new ClassPaymentVerification($enrollment, ClassPaymentVerification::PENDING);
    }

    /**
     * For Paystack's return page when the frontend only has `?reference=`.
     * Null when no enrolment has that payment.
     */
    public function findByPaymentReference(string $reference): ?ClassEnrollment
    {
        return $this->enrollments->findByPaymentReference($reference);
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

            if ($enrollment->payment_id !== null && $enrollment->payment_id !== $payment->paymentId) {
                // Payment keeps one payment per purpose_ref, so this means a bug or tampering. Needs a human.
                Log::channel('core')->critical('class enrolment paid by a different payment than its checkout', [
                    'enrollment_id' => $enrollment->public_id,
                    'expected_payment_id' => $enrollment->payment_id,
                    'payment_id' => $payment->paymentId,
                ]);

                return $enrollment;
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

        if ($enrollment->paid_at !== null) {
            // Every time, not only when newly confirmed: a redelivered event
            // repairs a view row an earlier attempt failed to write.
            $this->paymentViews->recordClassEnrollment($enrollment, PaymentView::STATUS_PAID, $payment->channel, $payment->paidAt);
        }

        if ($newlyConfirmed) {
            $this->afterConfirmation($enrollment);
        }

        return $enrollment;
    }

    /**
     * PaymentFailed for a class enrolment. Only the staff payments view
     * changes: the seat hold runs out on its own, and the family can try
     * again with a new enrolment. Idempotent.
     */
    public function recordPaymentFailure(string $enrollmentPublicId, string $paymentId): void
    {
        $enrollment = $this->enrollments->findByPublicId($enrollmentPublicId);

        if ($enrollment === null || $enrollment->payment_id !== $paymentId) {
            Log::channel('core')->warning('payment failure for unknown class enrolment payment', ['enrollment_id' => $enrollmentPublicId, 'payment_id' => $paymentId]);

            return;
        }

        $this->paymentViews->recordClassEnrollment($enrollment, PaymentView::STATUS_FAILED);
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

    /** @throws PaymentDoesNotMatchEnrollmentException */
    private function assertPaymentIsFor(ClassEnrollment $enrollment, PaymentStatus $status): void
    {
        $details = [
            'enrollment_id' => $enrollment->public_id,
            'payment_id' => $status->paymentId,
            'payment_purpose' => $status->purpose,
            'payment_purpose_ref' => $status->purposeRef,
        ];

        if ($status->paymentId !== $enrollment->payment_id
            || $status->purpose !== self::PAYMENT_PURPOSE
            || $status->purposeRef !== $enrollment->public_id) {
            Log::channel('core')->critical('payment does not match the class enrolment being verified', $details);

            throw new PaymentDoesNotMatchEnrollmentException($details);
        }
    }

    private function startCheckout(ClassEnrollment $enrollment, TewtoraClass $class, LearnerRef $learner, Account $payer, ?string $redirectUrl): ClassEnrollment
    {
        try {
            $checkout = $this->payments->startCheckout(
                purpose: self::PAYMENT_PURPOSE,
                purposeRef: $enrollment->public_id,
                payerAccountId: $payer->id,
                payerEmail: (string) $payer->email,
                amountMinor: $enrollment->amount_minor,
                currencyCode: $enrollment->currency_code,
                // Paystack appends ?trxref=&reference= to this; POST /class-enrollments/verify-payment takes that reference.
                callbackUrl: $redirectUrl ?? rtrim(config('app.frontend_url'), '/')."/classes/{$class->public_id}/enrollments/{$enrollment->public_id}/complete",
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

        $enrollment = $this->enrollments->update($enrollment, [
            'payment_id' => $checkout->paymentId,
            'checkout_url' => $checkout->checkoutUrl,
            'provider_reference' => $checkout->providerReference,
        ]);

        // Best-effort: the family is waiting on a checkout URL that already
        // exists. A missed row is written again on PaymentSucceeded/Failed,
        // or by `payments:sync-view`.
        rescue(fn () => $this->paymentViews->recordClassEnrollment($enrollment, PaymentView::STATUS_PENDING), function (Throwable $e) use ($enrollment) {
            Log::channel('core')->error('payment view row not written at checkout', ['enrollment_id' => $enrollment->public_id, 'exception' => $e]);
        }, report: false);

        return $enrollment;
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
