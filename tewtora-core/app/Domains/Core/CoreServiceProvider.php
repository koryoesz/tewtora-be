<?php

namespace App\Domains\Core;

use App\Domains\Core\Models\Feedback;
use App\Domains\Core\Models\MessageThread;
use App\Domains\Core\Models\MoveRequest;
use App\Domains\Core\Models\Plan;
use App\Domains\Core\Models\Session;
use App\Domains\Core\Models\TrialRequest;
use App\Domains\Core\Policies\FeedbackPolicy;
use App\Domains\Core\Policies\MessageThreadPolicy;
use App\Domains\Core\Policies\MoveRequestPolicy;
use App\Domains\Core\Policies\PlanPolicy;
use App\Domains\Core\Policies\SessionPolicy;
use App\Domains\Core\Policies\TrialRequestPolicy;
use App\Domains\Core\Repositories\EloquentFeedbackRepository;
use App\Domains\Core\Repositories\EloquentMessageRepository;
use App\Domains\Core\Repositories\EloquentMessageThreadRepository;
use App\Domains\Core\Repositories\EloquentMoveRequestRepository;
use App\Domains\Core\Repositories\EloquentPlanRepository;
use App\Domains\Core\Repositories\EloquentPricingSettingRepository;
use App\Domains\Core\Repositories\EloquentSessionRepository;
use App\Domains\Core\Repositories\EloquentTrialRequestRepository;
use App\Domains\Core\Repositories\FeedbackRepositoryInterface;
use App\Domains\Core\Repositories\MessageRepositoryInterface;
use App\Domains\Core\Repositories\MessageThreadRepositoryInterface;
use App\Domains\Core\Repositories\MoveRequestRepositoryInterface;
use App\Domains\Core\Repositories\PlanRepositoryInterface;
use App\Domains\Core\Repositories\PricingSettingRepositoryInterface;
use App\Domains\Core\Repositories\SessionRepositoryInterface;
use App\Domains\Core\Repositories\TrialRequestRepositoryInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SessionRepositoryInterface::class, EloquentSessionRepository::class);
        $this->app->bind(TrialRequestRepositoryInterface::class, EloquentTrialRequestRepository::class);
        $this->app->bind(FeedbackRepositoryInterface::class, EloquentFeedbackRepository::class);
        $this->app->bind(PlanRepositoryInterface::class, EloquentPlanRepository::class);
        $this->app->bind(MoveRequestRepositoryInterface::class, EloquentMoveRequestRepository::class);
        $this->app->bind(MessageThreadRepositoryInterface::class, EloquentMessageThreadRepository::class);
        $this->app->bind(MessageRepositoryInterface::class, EloquentMessageRepository::class);
        $this->app->bind(PricingSettingRepositoryInterface::class, EloquentPricingSettingRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(Session::class, SessionPolicy::class);
        Gate::policy(TrialRequest::class, TrialRequestPolicy::class);
        Gate::policy(Feedback::class, FeedbackPolicy::class);
        Gate::policy(Plan::class, PlanPolicy::class);
        Gate::policy(MoveRequest::class, MoveRequestPolicy::class);
        Gate::policy(MessageThread::class, MessageThreadPolicy::class);
    }
}
