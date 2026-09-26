<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * sender_account_id is a cross-schema identity (auth.accounts) —
 * intentionally not an Eloquent relationship. sender_role is denormalized
 * at write time from the authenticated request rather than resolved later
 * by joining into Auth's Account model.
 */
class Message extends Model
{
    public $timestamps = false;

    protected $table = 'core.messages';

    protected $fillable = [
        'thread_id',
        'sender_account_id',
        'sender_role',
        'body',
        'redacted',
    ];

    protected function casts(): array
    {
        return [
            'redacted' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function thread()
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }
}
