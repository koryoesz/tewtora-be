<?php

namespace App\Domains\Payment\Http\Controllers;

use App\Domains\Payment\Gateways\PaystackGateway;
use App\Domains\Payment\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/paystack (docs/microservices-architecture.md §7.3).
 *
 * 1. Verify the HMAC-SHA512 signature over the *raw* body before trusting
 *    anything in it. An unsigned or mis-signed call gets a 401 and changes nothing.
 * 2. charge.success -> settle the matching payment (idempotent: a retried
 *    webhook finds it already settled).
 * 3. Answer 200 for anything handled or deliberately ignored, so Paystack
 *    doesn't retry events we'll never act on.
 */
class PaystackWebhookController
{
    public function __construct(
        private readonly PaystackGateway $paystack,
        private readonly CheckoutService $checkouts,
    ) {}

    public function __invoke(Request $request)
    {
        $raw = $request->getContent();

        if (! $this->paystack->hasValidSignature($raw, $request->header('x-paystack-signature'))) {
            Log::channel('payment')->warning('rejected paystack webhook with a bad signature', ['ip' => $request->ip()]);

            return response()->json(['error' => ['code' => 'invalid_signature', 'message' => 'Invalid signature.']], 401);
        }

        $event = json_decode($raw, true);
        $type = $event['event'] ?? null;
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        if (! in_array($type, ['charge.success', 'charge.failed'], true) || empty($data['reference'])) {
            return response()->json(['received' => true]);
        }

        $payment = $this->checkouts->findByProviderReference((string) $data['reference']);

        if ($payment === null) {
            Log::channel('payment')->warning('paystack webhook for an unknown reference', ['reference' => $data['reference'], 'event' => $type]);

            return response()->json(['received' => true]);
        }

        $this->checkouts->settle($payment, $this->paystack->toVerified($data));

        return response()->json(['received' => true]);
    }
}
