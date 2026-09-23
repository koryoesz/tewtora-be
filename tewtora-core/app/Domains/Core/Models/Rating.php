<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

class Rating extends Model
{
    public $timestamps = false;

    protected $table = 'core.ratings';

    protected $fillable = [
        'session_id',
        'rated_by_account_id',
        'teacher_id',
        'stars',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'stars' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function session()
    {
        return $this->belongsTo(Session::class, 'session_id');
    }
}
