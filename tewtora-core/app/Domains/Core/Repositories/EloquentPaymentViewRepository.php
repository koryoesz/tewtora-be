<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\PaymentView;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentPaymentViewRepository implements PaymentViewRepositoryInterface
{
    /** API sort key => ORDER BY expression. Payer sorts by what staff see: the name, else the email. */
    private const SORTS = [
        'payer' => 'COALESCE(payer_name, payer_email)',
        'date' => 'created_at',
        'reference' => 'COALESCE(reference, payment_id)',
        'amount' => 'amount_minor',
        'status' => 'status',
    ];

    public function findByPaymentId(string $paymentId): ?PaymentView
    {
        return PaymentView::where('payment_id', $paymentId)->first();
    }

    public function lockByPaymentId(string $paymentId): ?PaymentView
    {
        return PaymentView::where('payment_id', $paymentId)->lockForUpdate()->first();
    }

    public function create(array $attributes): PaymentView
    {
        return PaymentView::create($attributes);
    }

    public function update(PaymentView $row, array $attributes): PaymentView
    {
        $row->update($attributes);

        return $row;
    }

    public function search(array $filters): LengthAwarePaginator
    {
        $dir = $filters['dir'] === 'asc' ? 'asc' : 'desc';

        return PaymentView::query()
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when(trim((string) ($filters['q'] ?? '')), function (Builder $q, string $term) {
                $like = '%'.addcslashes($term, '\\%_').'%';
                $q->where(fn (Builder $w) => $w
                    ->where('payer_name', 'like', $like)
                    ->orWhere('payer_email', 'like', $like)
                    ->orWhere('learner_name', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhere('payment_id', 'like', $like)
                    ->orWhere('for_label', 'like', $like));
            })
            ->orderByRaw(self::SORTS[$filters['sort']].' '.$dir)
            ->orderBy('id', $dir) // stable pages when the sort key ties
            ->paginate($filters['per_page'], ['*'], 'page', $filters['page']);
    }

    public function totals(CarbonInterface $from, CarbonInterface $to, string $currencyCode): array
    {
        $paid = PaymentView::where('currency_code', $currencyCode)
            ->where('status', PaymentView::STATUS_PAID)
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $to)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount_minor), 0) AS total')
            ->first();

        $created = PaymentView::where('currency_code', $currencyCode)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw("
                COUNT(*) AS attempts,
                COALESCE(SUM(status = 'pending'), 0) AS pending_count,
                COALESCE(SUM(IF(status = 'pending', amount_minor, 0)), 0) AS pending_minor,
                COALESCE(SUM(status = 'failed'), 0) AS failed_count,
                COALESCE(SUM(IF(status = 'refunded', amount_minor, 0)), 0) AS refunded_minor
            ")
            ->first();

        return [
            'collected_minor' => (int) $paid->total,
            'collected_count' => (int) $paid->n,
            'pending_minor' => (int) $created->pending_minor,
            'pending_count' => (int) $created->pending_count,
            'failed_count' => (int) $created->failed_count,
            'attempts' => (int) $created->attempts,
            'refunded_minor' => (int) $created->refunded_minor,
        ];
    }
}
