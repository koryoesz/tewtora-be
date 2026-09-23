<?php

namespace App\Domains\Payment\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * payer_account_id/session_id are cross-service identities (Auth's and
 * Core's own databases) — intentionally not Eloquent relationships; see
 * the migration for why there's no DB-level FK to relate() against.
 */
class Payment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'quote_id',
        'payer_account_id',
        'session_id',
        'payment_method_id',
        'provider_id',
        'plan_type',
        'sessions_covered',
        'amount_minor',
        'currency_code',
        'status',
        'provider_reference',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'sessions_covered' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function quote()
    {
        return $this->belongsTo(PaymentQuote::class, 'quote_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function provider()
    {
        return $this->belongsTo(PaymentProvider::class, 'provider_id');
    }

    public function lineItems()
    {
        return $this->hasMany(PaymentLineItem::class, 'payment_id');
    }
}
