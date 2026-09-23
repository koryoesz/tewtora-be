<?php

namespace App\Domains\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherAvailability extends Model
{
    public $timestamps = false;

    protected $table = 'auth.teacher_availability';

    protected $fillable = [
        'teacher_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }
}
