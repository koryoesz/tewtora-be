<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * learner_profile_id/teacher_id are cross-schema identities (auth.*) —
 * intentionally not Eloquent relationships, same as Plan. teacher_id is
 * null for a support thread (is_support = true); plan_id is null for the
 * same case — see the migration's chk_message_threads_shape.
 */
class MessageThread extends Model
{
    protected $table = 'core.message_threads';

    protected $fillable = [
        'plan_id',
        'learner_profile_id',
        'teacher_id',
        'is_support',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'is_support' => 'boolean',
            'last_message_at' => 'datetime',
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

    public function messages()
    {
        return $this->hasMany(Message::class, 'thread_id')->orderBy('created_at');
    }

    public function reads()
    {
        return $this->hasMany(MessageRead::class, 'thread_id');
    }

    /** Both are Core's own read-model tables — not cross-domain, unlike a live Auth query. */
    public function learnerAccountLink()
    {
        return $this->belongsTo(LearnerAccountLink::class, 'learner_profile_id', 'learner_profile_id');
    }

    public function teacherAccountLink()
    {
        return $this->belongsTo(TeacherAccountLink::class, 'teacher_id', 'teacher_id');
    }
}
