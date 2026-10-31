<?php

namespace App\Providers;

use App\Shared\Mail\QueuedMailable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Brand frame every domain's Mailables extend — backend-engineering-standards.md §5.
        $this->loadViewsFrom(app_path('Shared/Mail'), 'shared-mail');

        // One shared budget for every outgoing email (QueuedMailable's
        // middleware), sized to the Resend account's API rate limit — every
        // worker draws from it, so adding workers can't exceed it.
        RateLimiter::for('mail', fn () => Limit::perSecond((int) config('services.resend.rate_limit_per_second')));

        // `composer dev`: the framework's default worker only listens on
        // `default`, so queued mail would never be processed locally. The
        // scheduler runs outbox:relay (routes/console.php).
        DevCommands::artisan('queue:listen --queue='.QueuedMailable::QUEUE.',default --timeout=0', 'queue');
        DevCommands::artisan('schedule:work', 'scheduler');
    }
}
