<?php

namespace App\Domains\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class Assessment extends Model
{
    public $timestamps = false;

    protected $table = 'auth.assessments';

    protected $fillable = [
        'learner_profile_id',
        'status',
        'academic_challenges',
        'learning_goals',
        'budget_tier',
        'preferred_format',
        'session_frequency',
        'availability',
        'consent_given',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'academic_challenges' => 'array',
            'learning_goals' => 'array',
            'availability' => 'array',
            'consent_given' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }

    public function learnerProfile()
    {
        return $this->belongsTo(LearnerProfile::class, 'learner_profile_id');
    }

    public function subjects()
    {
        return $this->belongsToMany(
            Subject::class,
            'auth.assessment_subjects',
            'assessment_id',
            'subject_id'
        );
    }
}
