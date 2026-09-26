<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

/** account_id is a cross-schema identity (auth.accounts) — not a relationship. */
class MessageRead extends Model
{
    public $timestamps = false;

    protected $table = 'core.message_reads';

    protected $fillable = [
        'thread_id',
        'account_id',
        'last_read_at',
    ];

    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
        ];
    }

    public function thread()
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }
}
