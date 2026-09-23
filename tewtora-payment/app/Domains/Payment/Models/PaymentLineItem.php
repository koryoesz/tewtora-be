<?php

namespace App\Domains\Payment\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * session_id is a cross-service identity (Core's own database) —
 * intentionally not an Eloquent relationship. Only transition
 * 'held' -> 'released' from the feedback-submitted listener; never
 * re-apply to an already-released row (idempotency, same discipline as the
 * payment webhook handler).
 */
class PaymentLineItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payment_id',
        'session_id',
        'teacher_id',
        'amount_minor',
        'status',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'released_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
