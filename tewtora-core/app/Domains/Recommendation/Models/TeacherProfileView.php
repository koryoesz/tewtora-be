<?php

namespace App\Domains\Recommendation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local, event-synced projection of auth.teachers — kept up to date by the
 * TeacherVerified event consumer. See LearnerProfileView for why this
 * exists instead of a live join into Auth's schema.
 */
class TeacherProfileView extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'recommendation.teacher_profile_view';

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'public_id',
        'account_id',
        'verification_status',
        'subject_ids',
        'curriculum_ids',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'subject_ids' => 'array',
            'curriculum_ids' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function matches()
    {
        return $this->hasMany(TeacherMatch::class, 'teacher_id');
    }
}
