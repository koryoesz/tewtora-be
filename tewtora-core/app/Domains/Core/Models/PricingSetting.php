<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

class PricingSetting extends Model
{
    const CREATED_AT = null;

    protected $table = 'core.pricing_settings';

    protected $fillable = [
        'format',
        'rate_minor',
        'currency_code',
    ];

    protected function casts(): array
    {
        return [
            'rate_minor' => 'integer',
        ];
    }
}
