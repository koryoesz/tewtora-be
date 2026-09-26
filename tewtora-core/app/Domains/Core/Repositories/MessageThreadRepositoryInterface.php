<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Auth\Models\Account;
use App\Domains\Core\Models\MessageThread;
use App\Domains\Core\Models\Plan;
use Illuminate\Database\Eloquent\Collection;

interface MessageThreadRepositoryInterface
{
    public function findByPublicId(string $publicId): ?MessageThread;

    /**
     * A family (owner + linked child) sees their own threads (including
     * their support thread); a teacher sees their own plan threads; an
     * admin sees every support thread (any staff member can pick one up —
     * there's no per-incident assignment concept).
     */
    public function forAccount(Account $account): Collection;

    public function firstOrCreateForPlan(Plan $plan): MessageThread;

    public function firstOrCreateSupportThread(int $learnerProfileId): MessageThread;

    public function touchLastMessageAt(MessageThread $thread): void;
}
