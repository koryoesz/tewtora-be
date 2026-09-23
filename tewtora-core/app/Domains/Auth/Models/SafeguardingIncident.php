<?php

namespace App\Domains\Auth\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * session_id is a cross-schema reference into core.sessions — intentionally
 * not an Eloquent relationship.
 */
class SafeguardingIncident extends Model
{
    public $timestamps = false;

    protected $table = 'auth.safeguarding_incidents';

    protected $fillable = [
        'learner_profile_id',
        'teacher_id',
        'session_id',
        'reported_by_account_id',
        'severity',
        'status',
        'summary',
        'closed_note',
    ];

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function learnerProfile()
    {
        return $this->belongsTo(LearnerProfile::class, 'learner_profile_id');
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function reportedBy()
    {
        return $this->belongsTo(Account::class, 'reported_by_account_id');
    }
}
