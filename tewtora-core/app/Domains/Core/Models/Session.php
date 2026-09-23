<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * learner_profile_id/teacher_id/match_id are cross-schema identities
 * (auth.*, recommendation.*), intentionally not Eloquent relationships —
 * see the migration for why there's no DB-level FK to relate() against.
 * plan_id, unlike those, is same-schema and does have a real relationship
 * (plan()) — match_id is the one-time origin, plan_id is the operative
 * recurring link once a plan exists.
 */
class Session extends Model
{
    public $timestamps = false;

    protected $table = 'core.sessions';

    protected $fillable = [
        'match_id',
        'plan_id',
        'learner_profile_id',
        'teacher_id',
        'scheduled_at',
        'format',
        'is_trial',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'is_trial' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function feedback()
    {
        return $this->hasOne(Feedback::class, 'session_id');
    }

    public function ratings()
    {
        return $this->hasMany(Rating::class, 'session_id');
    }
}
