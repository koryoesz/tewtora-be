<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    public $timestamps = false;

    protected $table = 'core.feedback';

    protected $fillable = [
        'session_id',
        'teacher_id',
        'learner_profile_id',
        'attendance',
        'status',
        'session_notes',
        'progress_rating',
        'next_steps',
    ];

    protected function casts(): array
    {
        return [
            'progress_rating' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function session()
    {
        return $this->belongsTo(Session::class, 'session_id');
    }
}
