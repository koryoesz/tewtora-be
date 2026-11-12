<?php

namespace App\Domains\Core\Http\Resources;

use App\Domains\Core\Models\PaymentView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the staff Payments table. `id` is Payment's public id; the
 * payer's name falls back to their email, since parents have no name on
 * record. Money stays in minor units (CLAUDE.md "Money").
 *
 * @mixin PaymentView
 */
class AdminPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->payment_id,
            'payer' => [
                'account_id' => $this->payer_account_public_id,
                'name' => $this->payer_name ?? $this->payer_email,
                'email' => $this->payer_email,
            ],
            'learner_name' => $this->learner_name,
            'purpose' => $this->purpose,
            'for_label' => $this->for_label,
            'reference' => $this->reference ?? $this->payment_id,
            'amount_minor' => $this->amount_minor,
            'currency_code' => $this->currency_code,
            'status' => $this->status,
            'channel' => $this->channel,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'paid_at' => $this->paid_at?->toIso8601ZuluString(),
        ];
    }
}
