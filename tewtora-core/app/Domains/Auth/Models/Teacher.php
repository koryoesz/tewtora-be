<?php

namespace App\Domains\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use SoftDeletes;

    protected $table = 'auth.teachers';

    protected $fillable = [
        'account_id',
        'full_name',
        'years_experience',
        'bio',
        'preferred_format',
        'levels',
        'max_group_size',
        'rate_minor',
        'currency_code',
        'id_verified_at',
        'credentials_verified_at',
        'demo_status',
        'verification_status',
        'new_matches_suspended_at',
        'rating_avg',
    ];

    protected function casts(): array
    {
        return [
            'years_experience' => 'integer',
            'levels' => 'array',
            'max_group_size' => 'integer',
            'rate_minor' => 'integer',
            'id_verified_at' => 'datetime',
            'credentials_verified_at' => 'datetime',
            'new_matches_suspended_at' => 'datetime',
            'rating_avg' => 'decimal:2',
            'deleted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function subjects()
    {
        return $this->belongsToMany(
            Subject::class,
            'auth.teacher_subjects',
            'teacher_id',
            'subject_id'
        );
    }

    public function curricula()
    {
        return $this->belongsToMany(
            Curriculum::class,
            'auth.teacher_curricula',
            'teacher_id',
            'curriculum_id'
        );
    }

    public function availability()
    {
        return $this->hasMany(TeacherAvailability::class, 'teacher_id');
    }

    public function timeOff()
    {
        return $this->hasMany(TeacherTimeOff::class, 'teacher_id');
    }

    public function verificationChecks()
    {
        return $this->hasMany(TeacherVerificationCheck::class, 'teacher_id');
    }
}
