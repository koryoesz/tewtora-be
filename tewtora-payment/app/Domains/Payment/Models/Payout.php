<?php

namespace App\Domains\Payment\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * teacher_id is a cross-service identity (Auth's own database) —
 * intentionally not an Eloquent relationship.
 */
class Payout extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'teacher_id',
        'amount_minor',
        'currency_code',
        'method_id',
        'schedule',
        'period_start',
        'period_end',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function method()
    {
        return $this->belongsTo(PaymentMethod::class, 'method_id');
    }
}
