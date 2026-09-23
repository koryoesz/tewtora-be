<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * learner_profile_id/teacher_id/subject_id are cross-schema identities
 * (auth.*) — intentionally not Eloquent relationships. A learner holds many
 * plans concurrently (one per subject/teacher); never assume one plan per
 * learner.
 */
class Plan extends Model
{
    protected $table = 'core.plans';

    protected $fillable = [
        'learner_profile_id',
        'teacher_id',
        'subject_id',
        'format',
        'days',
        'time_of_day',
        'status',
        'rate_minor',
        'currency_code',
        'sessions_per_month',
        'sessions_remaining',
        'renews_at',
        'reference',
        'paid_to_date_minor',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'rate_minor' => 'integer',
            'sessions_per_month' => 'integer',
            'sessions_remaining' => 'integer',
            'renews_at' => 'datetime',
            'paid_to_date_minor' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function sessions()
    {
        return $this->hasMany(Session::class, 'plan_id');
    }

    public function goals()
    {
        return $this->hasMany(PlanGoal::class, 'plan_id');
    }

    public function moveRequests()
    {
        return $this->hasMany(MoveRequest::class, 'plan_id');
    }
}
