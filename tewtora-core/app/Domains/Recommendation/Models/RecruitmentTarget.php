<?php

namespace App\Domains\Recommendation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * subject_id/curriculum_id are cross-schema references into auth.* —
 * intentionally not Eloquent relationships.
 */
class RecruitmentTarget extends Model
{
    public $timestamps = false;

    protected $table = 'recommendation.recruitment_targets';

    protected $fillable = [
        'subject_id',
        'curriculum_id',
        'bookings_paused',
    ];

    protected function casts(): array
    {
        return [
            'bookings_paused' => 'boolean',
            'opened_at' => 'datetime',
        ];
    }
}
