<?php

namespace App\Domains\Payment\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    public $timestamps = false;

    protected $table = 'payment_methods';

    protected $fillable = [
        'code',
        'display_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
