<?php

namespace App\Domains\Payment\Repositories;

use App\Domains\Payment\Models\PaymentLineItem;
use Illuminate\Database\Eloquent\Collection;

interface PaymentLineItemRepositoryInterface
{
    public function heldForSession(int $sessionId): Collection;

    /** @return Collection<int, PaymentLineItem> only the ones actually transitioned, for the caller to report on */
    public function releaseHeldForSession(int $sessionId): Collection;

    public function forTeacher(int $teacherId, array $filters = []): Collection;
}
