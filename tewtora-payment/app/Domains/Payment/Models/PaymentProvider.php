<?php

namespace App\Domains\Payment\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentProvider extends Model
{
    public $timestamps = false;

    protected $table = 'payment_providers';

    protected $fillable = [
        'code',
        'display_name',
        'webhook_secret_ref',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'provider_id');
    }
}
