<?php

namespace App\Domains\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $table = 'auth.subjects';

    public $timestamps = false;

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
