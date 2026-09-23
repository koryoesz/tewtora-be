<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Denormalized rollup — not a source of truth (database-design.md §6).
 * Refresh via a background job/observer on Feedback/Rating writes; don't
 * write to this ad hoc from multiple code paths.
 */
class ProgressReport extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'core.progress_reports';

    protected $primaryKey = 'learner_profile_id';

    protected $keyType = 'int';

    protected $fillable = [
        'learner_profile_id',
        'attendance_pct',
        'goal_progress_pct',
        'sessions_completed',
    ];

    protected function casts(): array
    {
        return [
            'attendance_pct' => 'decimal:2',
            'goal_progress_pct' => 'decimal:2',
            'sessions_completed' => 'integer',
            'updated_at' => 'datetime',
        ];
    }
}
