<?php

namespace App\Domains\Auth\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Written in the same transaction as the state change it announces, then
 * relayed by `outbox:relay` (backend-engineering-standards.md §8). Never
 * publish to the queue directly from a request/controller.
 */
class OutboxEvent extends Model
{
    public $timestamps = false;

    protected $table = 'auth.outbox_events';

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
