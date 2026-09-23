<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Core's own outbox — publishes SessionCompleted. See
 * App\Domains\Auth\Models\OutboxEvent for the pattern rationale.
 */
class OutboxEvent extends Model
{
    public $timestamps = false;

    protected $table = 'core.outbox_events';

    protected $fillable = [
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
