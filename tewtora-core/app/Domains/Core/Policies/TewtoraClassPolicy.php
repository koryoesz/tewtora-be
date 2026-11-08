<?php

namespace App\Domains\Core\Policies;

use App\Domains\Auth\Contracts\AuthDirectory;
use App\Domains\Auth\Contracts\LearnerRef;
use App\Domains\Auth\Models\Account;
use App\Domains\Core\Models\TewtoraClass;
use App\Domains\Core\Repositories\ClassEnrollmentRepositoryInterface;

/**
 * The meeting link is the thing being paid for, so it's the most guarded
 * field: admins, the assigned teacher, and families with a confirmed seat.
 * Nobody else, including families who are mid-checkout.
 */
class TewtoraClassPolicy
{
    public function __construct(
        private readonly AuthDirectory $auth,
        private readonly ClassEnrollmentRepositoryInterface $enrollments,
    ) {}

    /**
     * Published and completed classes: any signed-in account (parents and
     * children both browse). Drafts: admins only. Cancelled: admins, the class
     * teacher, and families who had a seat — so they can see why.
     */
    public function view(Account $account, TewtoraClass $class): bool
    {
        if ($this->isAdmin($account)) {
            return true;
        }

        return match ($class->status) {
            TewtoraClass::STATUS_DRAFT => false,
            TewtoraClass::STATUS_CANCELLED => $this->isAssignedTeacher($account, $class)
                || $this->enrollments->hasAnyFor($class, $this->auth->learnerIdsVisibleTo($account->id), $account->id),
            default => true,
        };
    }

    public function viewRoster(Account $account, TewtoraClass $class): bool
    {
        return $this->isAdmin($account) || $this->isAssignedTeacher($account, $class);
    }

    /**
     * The payer is always the learner's owner (CLAUDE.md: "the payer is
     * always the account owner"): a parent for their child, an independent
     * student for themselves. Never a child's own login, a teacher or an admin.
     */
    public function enroll(Account $account, TewtoraClass $class, LearnerRef $learner): bool
    {
        return in_array($account->account_type, ['parent', 'independent_student'], true)
            && $learner->ownerAccountId === $account->id;
    }

    /** Only a family that paid for (or got a free) confirmed seat — the payer, not the child login. */
    public function rate(Account $account, TewtoraClass $class): bool
    {
        return in_array($account->account_type, ['parent', 'independent_student'], true)
            && $this->enrollments->hasConfirmedFor($class, [], $account->id);
    }

    public const LINK_ALWAYS = 'always';

    public const LINK_WINDOW = 'window';

    /**
     * Meeting-link access for a page of classes, in two queries however many
     * classes: admins and the class's teacher always see links; a family with
     * a confirmed seat sees each day's link inside its reveal window
     * (TewtoraClassSession::linkIsRevealable); anyone else never.
     *
     * @param  iterable<TewtoraClass>  $classes
     * @return array<int, string> class id => LINK_ALWAYS | LINK_WINDOW (absent = no access)
     */
    public function meetingLinkAccess(Account $account, iterable $classes): array
    {
        $classes = collect($classes);

        if ($this->isAdmin($account)) {
            return $classes->mapWithKeys(fn ($c) => [$c->id => self::LINK_ALWAYS])->all();
        }

        $access = $classes->filter(fn ($c) => $this->isAssignedTeacher($account, $c))
            ->mapWithKeys(fn ($c) => [$c->id => self::LINK_ALWAYS])->all();

        $open = $classes->where('status', '!=', TewtoraClass::STATUS_CANCELLED)->pluck('id')->all();
        foreach ($this->enrollments->classesWithConfirmedSeatFor($open, $this->auth->learnerIdsVisibleTo($account->id), $account->id) as $id) {
            $access[$id] ??= self::LINK_WINDOW;
        }

        return $access;
    }

    private function isAdmin(Account $account): bool
    {
        return $account->account_type === 'admin';
    }

    private function isAssignedTeacher(Account $account, TewtoraClass $class): bool
    {
        return $class->teacher_account_id !== null && $class->teacher_account_id === $account->id;
    }
}
