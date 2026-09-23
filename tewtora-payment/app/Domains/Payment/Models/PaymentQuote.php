<?php

namespace App\Domains\Payment\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * payer_account_id/teacher_id are cross-service identities (Auth's own
 * database) — intentionally not Eloquent relationships.
 */
class PaymentQuote extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payer_account_id',
        'teacher_id',
        'plan_kind',
        'session_count',
        'line_items',
        'total_minor',
        'currency_code',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'session_count' => 'integer',
            'line_items' => 'array',
            'total_minor' => 'integer',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'quote_id');
    }
}
