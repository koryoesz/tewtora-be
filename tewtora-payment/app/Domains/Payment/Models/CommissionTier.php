<?php

namespace App\Domains\Payment\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionTier extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'rate_type',
        'rate',
        'threshold_sessions',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:3',
            'threshold_sessions' => 'integer',
        ];
    }
}
