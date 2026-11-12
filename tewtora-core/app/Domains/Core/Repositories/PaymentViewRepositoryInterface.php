<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\PaymentView;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PaymentViewRepositoryInterface
{
    public function findByPaymentId(string $paymentId): ?PaymentView;

    /** Re-reads the row with a lock — call inside a transaction. Null if it doesn't exist. */
    public function lockByPaymentId(string $paymentId): ?PaymentView;

    public function create(array $attributes): PaymentView;

    public function update(PaymentView $row, array $attributes): PaymentView;

    /**
     * @param  array{q?: ?string, status?: ?string, sort: string, dir: string, page: int, per_page: int}  $filters
     */
    public function search(array $filters): LengthAwarePaginator;

    /**
     * Totals for one currency over [$from, $to).
     *
     * @return array{collected_minor: int, collected_count: int, pending_minor: int, pending_count: int, failed_count: int, attempts: int, refunded_minor: int}
     */
    public function totals(CarbonInterface $from, CarbonInterface $to, string $currencyCode): array;
}
