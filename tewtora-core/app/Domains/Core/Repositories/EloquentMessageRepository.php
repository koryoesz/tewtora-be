<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\Message;
use App\Domains\Core\Models\MessageThread;
use Illuminate\Database\Eloquent\Collection;

class EloquentMessageRepository implements MessageRepositoryInterface
{
    public function forThread(MessageThread $thread): Collection
    {
        return Message::where('thread_id', $thread->id)->orderBy('created_at')->get();
    }

    public function create(array $data): Message
    {
        return Message::create($data);
    }

    public function countSince(MessageThread $thread, ?\DateTimeInterface $since): int
    {
        $query = Message::where('thread_id', $thread->id);

        if ($since !== null) {
            $query->where('created_at', '>', $since);
        }

        return $query->count();
    }
}
