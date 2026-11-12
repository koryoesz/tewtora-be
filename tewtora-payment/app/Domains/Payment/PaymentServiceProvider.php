<?php

namespace App\Domains\Payment;

use App\Domains\Payment\Gateways\PaymentGateway;
use App\Domains\Payment\Gateways\PaystackGateway;
use App\Domains\Payment\Repositories\EloquentPaymentLineItemRepository;
use App\Domains\Payment\Repositories\PaymentLineItemRepositoryInterface;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentLineItemRepositoryInterface::class, EloquentPaymentLineItemRepository::class);

        // Paystack is the only provider today (docs/microservices-architecture.md §7.1).
        $this->app->bind(PaymentGateway::class, PaystackGateway::class);
    }
}
