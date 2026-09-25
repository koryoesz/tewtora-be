<?php

namespace Database\Seeders;

use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\Curriculum;
use App\Domains\Auth\Models\LearnerProfile;
use App\Domains\Auth\Models\Subject;
use App\Domains\Auth\Models\Teacher;
use App\Domains\Auth\Models\TeacherVerificationCheck;
use App\Domains\Core\Models\LearnerAccountLink;
use App\Domains\Core\Models\TeacherAccountLink;
use App\Domains\Recommendation\Models\LearnerProfileView;
use App\Domains\Recommendation\Models\TeacherProfileView;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local/dev-only fixture data — the 5 accounts from docs/test-accounts.md,
 * rebuilt as an idempotent seeder (updateOrCreate throughout) instead of the
 * one-off tinker script that doc originally described, since that script
 * doesn't survive a migrate:fresh. Never run against production: this
 * creates known-password logins.
 *
 * Also backfills the core/recommendation read-model tables
 * (learner_account_links, teacher_account_links, learner_profile_view,
 * teacher_profile_view) by hand, since no outbox listener that keeps them
 * in sync has been built yet (CLAUDE.md's cross-domain hard rule still
 * applies going forward — this is a seeding-time stand-in for that, not a
 * substitute for it).
 *
 * grade_level is free text (Form Requests only enforce string|max:60 — no
 * fixed vocabulary exists anywhere in docs/api-contract.md), so
 * LearnerProfileResource's grade_label is whatever was typed in verbatim.
 * Kept here as the slug style ("grade-8") to match the one example in
 * docs/frontend-integration-guide.md, so re-seeding any environment from
 * this file gives a consistent format rather than whatever an earlier
 * ad-hoc script happened to use.
 */
class TestAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $passwordHash = Hash::make('password123');

        $curriculum = Curriculum::query()->updateOrCreate(
            ['code' => 'ib'],
            ['display_name' => 'International Baccalaureate', 'is_active' => true],
        );

        $subject = Subject::query()->updateOrCreate(
            ['code' => 'mathematics'],
            ['display_name' => 'Mathematics', 'is_active' => true],
        );

        // public_id is DB-generated (DEFAULT (UUID())) — updateOrCreate's
        // in-memory model doesn't know it after an insert without a refresh.
        $parent = Account::query()->updateOrCreate(
            ['email' => 'parent@tewtora.test'],
            ['password_hash' => $passwordHash, 'account_type' => 'parent', 'email_verified_at' => now()],
        )->refresh();

        $child = Account::query()->updateOrCreate(
            ['email' => 'child@tewtora.test'],
            ['password_hash' => $passwordHash, 'account_type' => 'child', 'email_verified_at' => now()],
        )->refresh();

        $student = Account::query()->updateOrCreate(
            ['email' => 'student@tewtora.test'],
            ['password_hash' => $passwordHash, 'account_type' => 'independent_student', 'email_verified_at' => now()],
        )->refresh();

        $teacherAccount = Account::query()->updateOrCreate(
            ['email' => 'teacher@tewtora.test'],
            ['password_hash' => $passwordHash, 'account_type' => 'teacher', 'email_verified_at' => now()],
        )->refresh();

        $admin = Account::query()->updateOrCreate(
            ['email' => 'admin@tewtora.test'],
            ['password_hash' => $passwordHash, 'account_type' => 'admin', 'email_verified_at' => now()],
        )->refresh();

        $ada = LearnerProfile::withoutGlobalScopes()->updateOrCreate(
            ['owner_account_id' => $parent->id, 'full_name' => 'Ada'],
            [
                'linked_login_account_id' => $child->id,
                'profile_type' => 'child',
                'grade_level' => 'grade-8',
                'date_of_birth' => now()->subYears(13)->toDateString(),
                'curriculum_id' => $curriculum->id,
            ],
        )->refresh();

        $sam = LearnerProfile::withoutGlobalScopes()->updateOrCreate(
            ['owner_account_id' => $student->id, 'full_name' => 'Sam'],
            [
                'linked_login_account_id' => null,
                'profile_type' => 'own',
                'grade_level' => 'grade-11',
                'date_of_birth' => now()->subYears(16)->toDateString(),
                'curriculum_id' => $curriculum->id,
            ],
        )->refresh();

        $teacher = Teacher::query()->updateOrCreate(
            ['account_id' => $teacherAccount->id],
            [
                'years_experience' => 6,
                'bio' => 'Seeded test teacher for local API testing.',
                'preferred_format' => 'both',
                'max_group_size' => 4,
                'rate_minor' => 500000,
                'currency_code' => 'NGN',
                'id_verified_at' => now(),
                'credentials_verified_at' => now(),
                'demo_status' => 'completed',
                'verification_status' => 'approved',
            ],
        )->refresh();

        $teacher->subjects()->syncWithoutDetaching([$subject->id]);
        $teacher->curricula()->syncWithoutDetaching([$curriculum->id]);

        foreach (['government_id', 'credentials', 'teaching_demo'] as $kind) {
            TeacherVerificationCheck::query()->updateOrCreate(
                ['teacher_id' => $teacher->id, 'kind' => $kind],
                [
                    'state' => 'approved',
                    'checked_by_account_id' => $admin->id,
                    'checked_at' => now(),
                ],
            );
        }

        LearnerAccountLink::query()->updateOrCreate(
            ['learner_profile_id' => $ada->id],
            [
                'public_id' => $ada->public_id,
                'owner_account_id' => $ada->owner_account_id,
                'linked_login_account_id' => $ada->linked_login_account_id,
            ],
        );

        LearnerAccountLink::query()->updateOrCreate(
            ['learner_profile_id' => $sam->id],
            [
                'public_id' => $sam->public_id,
                'owner_account_id' => $sam->owner_account_id,
                'linked_login_account_id' => $sam->linked_login_account_id,
            ],
        );

        TeacherAccountLink::query()->updateOrCreate(
            ['teacher_id' => $teacher->id],
            ['account_id' => $teacher->account_id],
        );

        LearnerProfileView::query()->updateOrCreate(
            ['id' => $ada->id],
            [
                'public_id' => $ada->public_id,
                'grade_level' => $ada->grade_level,
                'curriculum_id' => $ada->curriculum_id,
                'subject_ids' => [$subject->id],
                'synced_at' => now(),
            ],
        );

        LearnerProfileView::query()->updateOrCreate(
            ['id' => $sam->id],
            [
                'public_id' => $sam->public_id,
                'grade_level' => $sam->grade_level,
                'curriculum_id' => $sam->curriculum_id,
                'subject_ids' => [$subject->id],
                'synced_at' => now(),
            ],
        );

        TeacherProfileView::query()->updateOrCreate(
            ['id' => $teacher->id],
            [
                'public_id' => $teacher->public_id,
                'account_id' => $teacher->account_id,
                'verification_status' => $teacher->verification_status,
                'subject_ids' => [$subject->id],
                'curriculum_ids' => [$curriculum->id],
                'synced_at' => now(),
            ],
        );

        $this->command?->table(
            ['Role', 'Email', 'public_id'],
            [
                ['parent', $parent->email, $parent->public_id],
                ['child', $child->email, $child->public_id],
                ['independent_student', $student->email, $student->public_id],
                ['teacher', $teacherAccount->email, $teacherAccount->public_id],
                ['admin', $admin->email, $admin->public_id],
            ],
        );
        $this->command?->info('Password for all: password123');
    }
}
