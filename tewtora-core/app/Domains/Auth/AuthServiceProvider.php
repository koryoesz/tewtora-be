<?php

namespace App\Domains\Auth;

use App\Domains\Auth\Models\Assessment;
use App\Domains\Auth\Models\LearnerProfile;
use App\Domains\Auth\Models\Teacher;
use App\Domains\Auth\Policies\AssessmentPolicy;
use App\Domains\Auth\Policies\LearnerProfilePolicy;
use App\Domains\Auth\Policies\TeacherPolicy;
use App\Domains\Auth\Repositories\AccountRepositoryInterface;
use App\Domains\Auth\Repositories\AssessmentRepositoryInterface;
use App\Domains\Auth\Repositories\EloquentAccountRepository;
use App\Domains\Auth\Repositories\EloquentAssessmentRepository;
use App\Domains\Auth\Repositories\EloquentLearnerProfileRepository;
use App\Domains\Auth\Repositories\EloquentTeacherRepository;
use App\Domains\Auth\Repositories\LearnerProfileRepositoryInterface;
use App\Domains\Auth\Repositories\TeacherRepositoryInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AccountRepositoryInterface::class, EloquentAccountRepository::class);
        $this->app->bind(LearnerProfileRepositoryInterface::class, EloquentLearnerProfileRepository::class);
        $this->app->bind(TeacherRepositoryInterface::class, EloquentTeacherRepository::class);
        $this->app->bind(AssessmentRepositoryInterface::class, EloquentAssessmentRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(LearnerProfile::class, LearnerProfilePolicy::class);
        Gate::policy(Teacher::class, TeacherPolicy::class);
        Gate::policy(Assessment::class, AssessmentPolicy::class);
    }
}
