<?php

namespace App\Shared\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base for every Tewtora email (backend-engineering-standards.md §5: "queued
 * by default — never sent synchronously in a request"). Gives each Mailable
 * the same delivery behaviour, so a new one only has to define its content:
 *
 * - its own `mail` queue, so a burst of emails never waits behind other
 *   jobs, and workers can prioritise it (`queue:work --queue=mail,default`);
 * - dispatched only after the surrounding DB transaction commits, so an
 *   email never goes out for a row that was then rolled back;
 * - throttled to Resend's API rate limit through the `mail` rate limiter
 *   (AppServiceProvider). A throttled job is released back onto the
 *   queue, not failed;
 * - retried with growing backoff when the send itself throws (Resend
 *   outage, network): up to $maxExceptions real errors, inside a
 *   $retryUntil window. Time-based rather than $tries, because every
 *   rate-limit release also counts as an attempt;
 * - a failed() hook that logs the final failure. The job itself stays in
 *   failed_jobs for `php artisan queue:retry`.
 */
abstract class QueuedMailable extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'mail';

    /** Genuine send errors (not rate-limit releases) before the job is failed. */
    public int $maxExceptions = 5;

    /** Seconds one send may take before the worker kills it. */
    public int $timeout = 30;

    /** Log channel for the final failure — the owning domain's. */
    protected string $logChannel = 'stack';

    public function __construct()
    {
        $this->onQueue(self::QUEUE);
        $this->afterCommit();
    }

    /** Stop retrying this long after dispatch, however many rate-limit releases happened. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    /** @return list<int> seconds before each retry after a send error */
    public function backoff(): array
    {
        return [10, 60, 300, 900, 1800];
    }

    public function middleware(): array
    {
        // A sync-queue job can't be released, so the limiter would silently
        // drop the email instead of delaying it.
        if (config('queue.default') === 'sync') {
            return [];
        }

        return [new RateLimited('mail')];
    }

    public function failed(Throwable $e): void
    {
        Log::channel($this->logChannel)->error('email could not be sent', [
            'mailable' => static::class,
            ...$this->failureContext(),
            'exception' => $e,
        ]);
    }

    /** Identifiers that make the failure traceable — public_ids, never PII. */
    protected function failureContext(): array
    {
        return [];
    }
}
