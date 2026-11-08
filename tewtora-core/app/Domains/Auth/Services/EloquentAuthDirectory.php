<?php

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Contracts\AuthDirectory;
use App\Domains\Auth\Contracts\CurriculumRef;
use App\Domains\Auth\Contracts\LearnerRef;
use App\Domains\Auth\Contracts\SubjectRef;
use App\Domains\Auth\Contracts\TeacherRef;
use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\Curriculum;
use App\Domains\Auth\Models\LearnerProfile;
use App\Domains\Auth\Models\Subject;
use App\Domains\Auth\Models\Teacher;

/**
 * In-process implementation of AuthDirectory. withoutGlobalScopes() on
 * LearnerProfile: the ownedByAccount scope would hide every learner the
 * current user doesn't own, but the caller (another domain's policy or
 * service) needs to *see* the owner to decide that itself.
 */
class EloquentAuthDirectory implements AuthDirectory
{
    public function learner(string $publicId): ?LearnerRef
    {
        $profile = LearnerProfile::withoutGlobalScopes()->where('public_id', $publicId)->first();

        return $profile ? $this->toLearnerRef($profile) : null;
    }

    public function learnersByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return LearnerProfile::withoutGlobalScopes()->whereIn('id', array_unique($ids))->get()
            ->mapWithKeys(fn (LearnerProfile $p) => [$p->id => $this->toLearnerRef($p)])
            ->all();
    }

    public function learnerIdsVisibleTo(int $accountId): array
    {
        return LearnerProfile::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('owner_account_id', $accountId)->orWhere('linked_login_account_id', $accountId))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function accountEmail(int $accountId): ?string
    {
        return Account::whereKey($accountId)->value('email');
    }

    public function approvedTeacher(string $publicId): ?TeacherRef
    {
        $teacher = Teacher::where('public_id', $publicId)->where('verification_status', 'approved')->first();

        return $teacher ? $this->toTeacherRef($teacher) : null;
    }

    public function teachersByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Teacher::whereIn('id', array_unique($ids))->get()
            ->mapWithKeys(fn (Teacher $t) => [$t->id => $this->toTeacherRef($t)])
            ->all();
    }

    public function subject(string $code): ?SubjectRef
    {
        $subject = Subject::where('code', $code)->where('is_active', true)->first();

        return $subject ? new SubjectRef($subject->id, $subject->code, $subject->display_name) : null;
    }

    public function subjectsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Subject::whereIn('id', array_unique($ids))->get()
            ->mapWithKeys(fn (Subject $s) => [$s->id => new SubjectRef($s->id, $s->code, $s->display_name)])
            ->all();
    }

    public function curriculum(string $code): ?CurriculumRef
    {
        $curriculum = Curriculum::where('code', $code)->where('is_active', true)->first();

        return $curriculum ? new CurriculumRef($curriculum->id, $curriculum->code, $curriculum->display_name) : null;
    }

    public function curriculaByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Curriculum::whereIn('id', array_unique($ids))->get()
            ->mapWithKeys(fn (Curriculum $c) => [$c->id => new CurriculumRef($c->id, $c->code, $c->display_name)])
            ->all();
    }

    private function toLearnerRef(LearnerProfile $profile): LearnerRef
    {
        return new LearnerRef(
            (int) $profile->id,
            (string) $profile->public_id,
            (string) $profile->full_name,
            (int) $profile->owner_account_id,
            $profile->linked_login_account_id !== null ? (int) $profile->linked_login_account_id : null,
        );
    }

    private function toTeacherRef(Teacher $teacher): TeacherRef
    {
        return new TeacherRef((int) $teacher->id, (string) $teacher->public_id, (int) $teacher->account_id, $teacher->full_name);
    }
}
