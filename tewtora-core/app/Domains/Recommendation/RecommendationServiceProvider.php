<?php

namespace App\Domains\Recommendation;

use App\Domains\Recommendation\Models\TeacherMatch;
use App\Domains\Recommendation\Policies\TeacherMatchPolicy;
use App\Domains\Recommendation\Repositories\EloquentTeacherMatchRepository;
use App\Domains\Recommendation\Repositories\TeacherMatchRepositoryInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * backend-engineering-standards.md §2: each domain's repository bindings
 * travel with it, not dumped into one shared AppServiceProvider — this is
 * what makes the later split into a real separate service a redeploy, not
 * a rewrite.
 */
class RecommendationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TeacherMatchRepositoryInterface::class, EloquentTeacherMatchRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(TeacherMatch::class, TeacherMatchPolicy::class);
    }
}
