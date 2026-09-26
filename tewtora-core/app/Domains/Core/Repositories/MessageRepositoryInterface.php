<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\Message;
use App\Domains\Core\Models\MessageThread;
use Illuminate\Database\Eloquent\Collection;

interface MessageRepositoryInterface
{
    public function forThread(MessageThread $thread): Collection;

    public function create(array $data): Message;

    public function countSince(MessageThread $thread, ?\DateTimeInterface $since): int;
}
