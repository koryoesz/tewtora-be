<?php

namespace App\Domains\Core\Services;

use App\Domains\Auth\Contracts\AuthDirectory;
use App\Domains\Core\Models\ClassEnrollment;
use App\Domains\Core\Models\PaymentView;
use App\Domains\Core\Repositories\ClassEnrollmentRepositoryInterface;
use App\Domains\Core\Repositories\PaymentViewRepositoryInterface;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Keeps core.payment_view in step with the payments Core starts, and reads
 * it for the staff Payments page (/internal/payments).
 *
 * Writers: ClassEnrollmentService when a checkout starts (pending), when a
 * payment is confirmed (paid, from PaymentSucceeded or the payer's verify
 * call), and when Payment reports a failure (PaymentFailed); plus the
 * `payments:sync-view` backfill. Every write is an idempotent upsert on
 * payment_id that only moves status forward (PaymentView::canBecome), so
 * at-least-once, out-of-order delivery is safe (CLAUDE.md "Idempotency").
 */
class PaymentViewService
{
    public function __construct(
        private readonly PaymentViewRepositoryInterface $rows,
        private readonly AuthDirectory $auth,
        private readonly ClassEnrollmentRepositoryInterface $enrollments,
    ) {}

    /**
     * Backfill/repair from class_enrollments (`payments:sync-view`). Core
     * only knows paid vs not, so an unpaid one is written as pending; a
     * row that already has an outcome keeps it. Returns rows touched.
     */
    public function syncClassEnrollments(): int
    {
        $count = 0;

        $this->enrollments->eachWithPayment(function (ClassEnrollment $enrollment) use (&$count) {
            $status = $enrollment->paid_at !== null ? PaymentView::STATUS_PAID : PaymentView::STATUS_PENDING;
            $this->recordClassEnrollment($enrollment, $status);
            $count++;
        });

        return $count;
    }

    /**
     * Upserts the row for a class enrolment's payment. A no-op when the
     * enrolment has no payment yet (a free class, or a checkout that never started).
     */
    public function recordClassEnrollment(
        ClassEnrollment $enrollment,
        string $status,
        ?string $channel = null,
        ?CarbonInterface $paidAt = null,
    ): ?PaymentView {
        if ($enrollment->payment_id === null) {
            return null;
        }

        $payer = $this->auth->accountsByIds([$enrollment->payer_account_id])[$enrollment->payer_account_id] ?? null;
        $learner = $this->auth->learnersByIds([$enrollment->learner_profile_id])[$enrollment->learner_profile_id] ?? null;

        return $this->upsert($enrollment->payment_id, $status, [
            'purpose' => PaymentView::PURPOSE_CLASS_ENROLLMENT,
            'purpose_ref' => $enrollment->public_id,
            'payer_account_id' => $enrollment->payer_account_id,
            'payer_account_public_id' => $payer?->publicId ?? '',
            'payer_name' => $payer?->name,
            'payer_email' => $payer?->email,
            'learner_profile_id' => $enrollment->learner_profile_id,
            'learner_name' => $learner?->fullName,
            'for_label' => mb_substr((string) $enrollment->tewtoraClass?->title, 0, 200),
            'reference' => $enrollment->provider_reference,
            'amount_minor' => $enrollment->amount_minor,
            'currency_code' => $enrollment->currency_code,
            'created_at' => $enrollment->created_at ?? now(),
        ], array_filter([
            'channel' => $channel,
            'paid_at' => $status === PaymentView::STATUS_PAID ? CarbonImmutable::instance($paidAt ?? $enrollment->paid_at ?? now())->utc() : null,
        ], fn ($v) => $v !== null));
    }

    /**
     * @param  array{q?: ?string, status?: ?string, sort?: string, dir?: string, page?: int, per_page?: int}  $filters
     */
    public function search(array $filters): LengthAwarePaginator
    {
        return $this->rows->search([
            'q' => $filters['q'] ?? null,
            'status' => $filters['status'] ?? null,
            'sort' => $filters['sort'] ?? 'date',
            'dir' => $filters['dir'] ?? 'desc',
            'page' => (int) ($filters['page'] ?? 1),
            'per_page' => (int) ($filters['per_page'] ?? 12),
        ]);
    }

    /**
     * KPI totals for whole Lagos days $from..$to inclusive; the current
     * Lagos calendar month when neither is given. Collected counts by when
     * the money landed; pending/failed by when the attempt started.
     *
     * @return array{collected_minor: int, collected_count: int, pending_minor: int, pending_count: int, failed_count: int, failed_rate: int, refunded_minor: int, currency_code: string}
     */
    public function summary(?string $from, ?string $to, string $currencyCode): array
    {
        $tz = (string) config('classes.display_timezone');
        $now = CarbonImmutable::now($tz);

        $start = $from !== null ? CarbonImmutable::parse($from, $tz)->startOfDay() : $now->startOfMonth();
        $end = ($to !== null ? CarbonImmutable::parse($to, $tz) : ($from !== null ? $now : $now->endOfMonth()))->startOfDay()->addDay();

        $totals = $this->rows->totals($start->utc(), $end->utc(), $currencyCode);

        return [
            'collected_minor' => $totals['collected_minor'],
            'collected_count' => $totals['collected_count'],
            'pending_minor' => $totals['pending_minor'],
            'pending_count' => $totals['pending_count'],
            'failed_count' => $totals['failed_count'],
            'failed_rate' => $totals['attempts'] > 0 ? (int) round($totals['failed_count'] * 100 / $totals['attempts']) : 0,
            'refunded_minor' => $totals['refunded_minor'],
            'currency_code' => $currencyCode,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot  written on insert only (who/what/when)
     * @param  array<string, mixed>  $outcome  written whenever the status may change
     */
    private function upsert(string $paymentId, string $status, array $snapshot, array $outcome): PaymentView
    {
        try {
            return DB::transaction(function () use ($paymentId, $status, $snapshot, $outcome) {
                $row = $this->rows->lockByPaymentId($paymentId);

                if ($row === null) {
                    return $this->rows->create(['payment_id' => $paymentId, 'status' => $status] + $outcome + $snapshot);
                }

                if (! $row->canBecome($status)) {
                    return $row; // stale or duplicate event — the row already has a later outcome
                }

                return $this->rows->update($row, ['status' => $status, 'reference' => $snapshot['reference'] ?? $row->reference] + $outcome);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent writer inserted it first; apply this outcome on top.
            return $this->upsert($paymentId, $status, $snapshot, $outcome);
        }
    }
}
