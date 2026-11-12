<?php

namespace App\Domains\Payment\Http\Controllers;

use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /internal/checkouts — called by tewtora-core only (VerifyInternalToken),
 * never by a client. Core authenticates the user and decides the amount;
 * Payment talks to the provider.
 */
class InternalCheckoutController
{
    public function __construct(
        private readonly CheckoutService $checkouts,
    ) {}

    /** 201 for a new checkout, 200 when this purpose_ref already has one. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'purpose' => ['required', Rule::in([Payment::PURPOSE_CLASS_ENROLLMENT])],
            'purpose_ref' => ['required', 'string', 'max:64'],
            'payer_account_id' => ['required', 'integer', 'min:1'],
            'email' => ['required', 'email', 'max:255'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency_code' => ['required', 'string', 'size:3'],
            'callback_url' => ['required', 'url', 'max:500'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $payment = $this->checkouts->start($data);

        return response()->json(['data' => $this->present($payment)], $payment->wasRecentlyCreated ? 201 : 200);
    }

    /** Re-verifies with the provider while the payment's outcome can still change (processing, or failed). */
    public function show(Payment $payment)
    {
        return response()->json(['data' => $this->present($this->checkouts->refresh($payment))]);
    }

    private function present(Payment $payment): array
    {
        return [
            'payment_id' => $payment->public_id,
            'purpose' => $payment->purpose,
            'purpose_ref' => $payment->purpose_ref,
            'status' => $payment->status,
            'checkout_url' => $payment->status === 'processing' ? $payment->checkout_url : null,
            'provider_reference' => $payment->provider_reference,
            'amount_minor' => $payment->amount_minor,
            'currency_code' => $payment->currency_code,
            'channel' => $payment->channel,
            'paid_at' => $payment->paid_at?->toIso8601ZuluString(),
        ];
    }
}
