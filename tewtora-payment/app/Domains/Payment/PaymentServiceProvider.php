<?php

namespace App\Domains\Payment;

use App\Domains\Payment\Repositories\EloquentPaymentLineItemRepository;
use App\Domains\Payment\Repositories\PaymentLineItemRepositoryInterface;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentLineItemRepositoryInterface::class, EloquentPaymentLineItemRepository::class);
    }
}
