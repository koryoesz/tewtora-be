<?php

namespace App\Domains\Payment\Services;

use App\Domains\Payment\Models\CommissionTier;
use App\Domains\Payment\Models\PaymentLineItem;
use App\Domains\Payment\Models\Payout;
use App\Domains\Payment\Repositories\PaymentLineItemRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * docs/api-contract.md §10: LedgerEntry merges payment_line_items
 * (kind: 'session') and payouts (kind: 'payout') into one timeline.
 * `learnerLabel` is left blank — Payment has no read-model for learner
 * names (only teacher_id/session_id are denormalized onto line items),
 * and resolving one wasn't in scope for this pass.
 */
class LedgerService
{
    public function __construct(
        private readonly PaymentLineItemRepositoryInterface $lineItems,
    ) {}

    public function entriesForTeacher(int $teacherId, array $filters = []): Collection
    {
        $sessionEntries = $this->lineItems->forTeacher($teacherId, $filters)->map(fn ($item) => [
            'occurred_at' => $item->created_at,
            'learner_label' => '',
            'detail' => 'Session',
            'reference' => (string) $item->payment_id,
            'kind' => 'session',
            'gross_minor' => $item->amount_minor,
            'status' => $item->status,
        ]);

        $payoutEntries = Payout::where('teacher_id', $teacherId)->get()->map(fn ($payout) => [
            'occurred_at' => $payout->period_end,
            'learner_label' => '',
            'detail' => 'Payout',
            'reference' => (string) $payout->id,
            'kind' => 'payout',
            'gross_minor' => $payout->amount_minor,
            'status' => $payout->status === 'paid' ? 'paid' : $payout->status,
        ]);

        return $sessionEntries->concat($payoutEntries)->sortByDesc('occurred_at')->values();
    }

    public function commissionInfo(int $teacherId): array
    {
        $standard = CommissionTier::where('rate_type', 'standard')->first();
        $reduced = CommissionTier::where('rate_type', 'reduced')->first();

        // A released line item implies its session was taught and fed
        // back on — the closest proxy Payment can compute on its own for
        // "completed sessions," since the actual count lives in Core.
        $completedSessionsLifetime = PaymentLineItem::where('teacher_id', $teacherId)
            ->where('status', 'released')
            ->distinct('session_id')
            ->count('session_id');

        return [
            'teacher_id' => $teacherId,
            'completed_sessions_lifetime' => $completedSessionsLifetime,
            'standard_rate' => $standard ? (float) $standard->rate : null,
            'reduced_rate' => $reduced ? (float) $reduced->rate : null,
            'reduced_tier_at' => $reduced?->threshold_sessions,
        ];
    }
}
