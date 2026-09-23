<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

class MoveRequest extends Model
{
    public $timestamps = false;

    protected $table = 'core.move_requests';

    protected $fillable = [
        'plan_id',
        'kind',
        'route',
        'reason',
        'from_day',
        'from_starts_at',
        'to_day',
        'to_starts_at',
        'outside_teacher_hours',
        'status',
        'expires_at',
        'gross_minor',
    ];

    protected function casts(): array
    {
        return [
            'outside_teacher_hours' => 'boolean',
            'expires_at' => 'datetime',
            'gross_minor' => 'integer',
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

    public function approvals()
    {
        return $this->hasMany(MoveApproval::class, 'move_request_id');
    }
}
