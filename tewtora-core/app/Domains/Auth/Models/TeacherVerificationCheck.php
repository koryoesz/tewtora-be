<?php

namespace App\Domains\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherVerificationCheck extends Model
{
    protected $table = 'auth.teacher_verification_checks';

    protected $fillable = [
        'teacher_id',
        'kind',
        'state',
        'evidence',
        'checked_by_account_id',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function checkedBy()
    {
        return $this->belongsTo(Account::class, 'checked_by_account_id');
    }
}
