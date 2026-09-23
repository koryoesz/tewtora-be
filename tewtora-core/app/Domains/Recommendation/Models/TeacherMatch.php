<?php

namespace App\Domains\Recommendation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps to the `matches` table (database-design.md §3.4). Named TeacherMatch
 * rather than Match — `match` has been a reserved PHP keyword since 8.0.
 */
class TeacherMatch extends Model
{
    public $timestamps = false;

    protected $table = 'recommendation.matches';

    protected $fillable = [
        'learner_profile_id',
        'teacher_id',
        'match_reasoning',
        'status',
        'decline_reason',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function learnerProfile()
    {
        return $this->belongsTo(LearnerProfileView::class, 'learner_profile_id');
    }

    public function teacher()
    {
        return $this->belongsTo(TeacherProfileView::class, 'teacher_id');
    }
}
