<?php

namespace App\Domains\Recommendation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local, event-synced projection of auth.learner_profiles — Recommendation
 * never queries Auth's tables directly (microservices-architecture.md §2).
 * Kept up to date by the LearnerProfileUpdated event consumer.
 */
class LearnerProfileView extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'recommendation.learner_profile_view';

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'public_id',
        'grade_level',
        'curriculum_id',
        'challenges',
        'goals',
        'preferred_slots',
        'budget_min_minor',
        'budget_max_minor',
        'subject_ids',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'curriculum_id' => 'integer',
            'challenges' => 'array',
            'goals' => 'array',
            'preferred_slots' => 'array',
            'budget_min_minor' => 'integer',
            'budget_max_minor' => 'integer',
            'subject_ids' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function matches()
    {
        return $this->hasMany(TeacherMatch::class, 'learner_profile_id');
    }
}
