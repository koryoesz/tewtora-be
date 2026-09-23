<?php

namespace App\Domains\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherTimeOff extends Model
{
    public $timestamps = false;

    protected $table = 'auth.teacher_time_off';

    protected $fillable = [
        'teacher_id',
        'starts_at',
        'ends_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }
}
