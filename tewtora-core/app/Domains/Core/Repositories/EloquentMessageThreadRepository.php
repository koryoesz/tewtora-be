<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Auth\Models\Account;
use App\Domains\Core\Models\LearnerAccountLink;
use App\Domains\Core\Models\MessageThread;
use App\Domains\Core\Models\Plan;
use App\Domains\Core\Models\TeacherAccountLink;
use Illuminate\Database\Eloquent\Collection;

class EloquentMessageThreadRepository implements MessageThreadRepositoryInterface
{
    public function findByPublicId(string $publicId): ?MessageThread
    {
        return MessageThread::where('public_id', $publicId)->first();
    }

    public function forAccount(Account $account): Collection
    {
        if ($account->account_type === 'admin') {
            return MessageThread::where('is_support', true)->orderByDesc('last_message_at')->get();
        }

        $learnerIds = LearnerAccountLink::where('owner_account_id', $account->id)
            ->orWhere('linked_login_account_id', $account->id)
            ->pluck('learner_profile_id');

        $teacherId = TeacherAccountLink::where('account_id', $account->id)->value('teacher_id');

        return MessageThread::where(function ($query) use ($learnerIds, $teacherId) {
            $query->whereIn('learner_profile_id', $learnerIds);

            if ($teacherId !== null) {
                $query->orWhere('teacher_id', $teacherId);
            }
        })->orderByDesc('last_message_at')->get();
    }

    public function firstOrCreateForPlan(Plan $plan): MessageThread
    {
        return MessageThread::firstOrCreate(
            ['plan_id' => $plan->id],
            [
                'learner_profile_id' => $plan->learner_profile_id,
                'teacher_id' => $plan->teacher_id,
                'is_support' => false,
            ],
        );
    }

    public function firstOrCreateSupportThread(int $learnerProfileId): MessageThread
    {
        return MessageThread::firstOrCreate(
            ['learner_profile_id' => $learnerProfileId, 'is_support' => true],
            ['plan_id' => null, 'teacher_id' => null],
        );
    }

    public function touchLastMessageAt(MessageThread $thread): void
    {
        $thread->update(['last_message_at' => now()]);
    }
}
