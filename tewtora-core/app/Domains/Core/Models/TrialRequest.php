<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * learner_profile_id/teacher_id are cross-schema identities (auth.*) —
 * intentionally not Eloquent relationships.
 */
class TrialRequest extends Model
{
    public $timestamps = false;

    protected $table = 'core.trial_requests';

    protected $fillable = [
        'learner_profile_id',
        'teacher_id',
        'session_id',
        'slot_starts_at',
        'duration_minutes',
        'status',
        'responded_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'slot_starts_at' => 'datetime',
            'duration_minutes' => 'integer',
            'responded_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function session()
    {
        return $this->belongsTo(Session::class, 'session_id');
    }
}
