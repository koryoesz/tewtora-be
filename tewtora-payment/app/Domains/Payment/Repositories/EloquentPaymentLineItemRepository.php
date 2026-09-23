<?php

namespace App\Domains\Payment\Repositories;

use App\Domains\Payment\Models\PaymentLineItem;
use Illuminate\Database\Eloquent\Collection;

class EloquentPaymentLineItemRepository implements PaymentLineItemRepositoryInterface
{
    public function heldForSession(int $sessionId): Collection
    {
        return PaymentLineItem::where('session_id', $sessionId)
            ->where('status', 'held')
            ->get();
    }

    public function releaseHeldForSession(int $sessionId): Collection
    {
        $items = $this->heldForSession($sessionId);

        $items->each(fn (PaymentLineItem $item) => $item->update([
            'status' => 'released',
            'released_at' => now(),
        ]));

        return $items;
    }

    public function forTeacher(int $teacherId, array $filters = []): Collection
    {
        $query = PaymentLineItem::where('teacher_id', $teacherId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('created_at')->get();
    }
}
