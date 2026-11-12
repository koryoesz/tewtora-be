<?php

namespace App\Domains\Core\Services;

use App\Domains\Auth\Contracts\AuthDirectory;
use App\Domains\Auth\Models\Account;
use App\Domains\Core\Exceptions\ClassCapacityBelowEnrollmentsException;
use App\Domains\Core\Exceptions\ClassFeeLockedException;
use App\Domains\Core\Exceptions\ClassNotEditableException;
use App\Domains\Core\Exceptions\ClassNotPublishableException;
use App\Domains\Core\Mail\ClassCancelled;
use App\Domains\Core\Models\ClassEnrollment;
use App\Domains\Core\Models\TewtoraClass;
use App\Domains\Core\Repositories\ClassEnrollmentRepositoryInterface;
use App\Domains\Core\Repositories\TewtoraClassRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Admin side of Tewtora classes: create, edit, publish, cancel. The rules
 * that protect people who have already enrolled (and may have paid) live
 * here, not in the controller:
 * - the price is frozen once anyone has enrolled;
 * - capacity can't drop below the seats already taken;
 * - cancelled/completed classes are read-only.
 */
class TewtoraClassService
{
    public function __construct(
        private readonly TewtoraClassRepositoryInterface $classes,
        private readonly ClassEnrollmentRepositoryInterface $enrollments,
        private readonly AuthDirectory $auth,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  already resolved to column names
     * @param  list<array{starts_at: string, ends_at: string}>  $sessions
     */
    public function create(Account $admin, array $attributes, array $sessions, ?UploadedFile $image, bool $publish): TewtoraClass
    {
        $class = $this->classes->create($attributes + [
            'status' => TewtoraClass::STATUS_DRAFT,
            'created_by_account_id' => $admin->id,
        ], $sessions);

        if ($image !== null) {
            $class = $this->replaceImage($class, $image);
        }

        $this->log('class created', $admin, $class);

        return $publish ? $this->publish($admin, $class) : $class;
    }

    /**
     * @param  array<string, mixed>  $attributes  only the fields being changed
     * @param  list<array{starts_at: string, ends_at: string}>|null  $sessions
     */
    public function update(Account $admin, TewtoraClass $class, array $attributes, ?array $sessions): TewtoraClass
    {
        $class = DB::transaction(function () use ($class, $attributes, $sessions) {
            $class = $this->classes->lockForUpdate($class);
            $this->assertEditable($class);

            $feeChanging = (array_key_exists('price_minor', $attributes) && $attributes['price_minor'] !== $class->price_minor)
                || (array_key_exists('currency_code', $attributes) && $attributes['currency_code'] !== $class->currency_code);

            if ($feeChanging && $this->enrollments->existsFor($class)) {
                throw new ClassFeeLockedException;
            }

            if (array_key_exists('capacity', $attributes) && $attributes['capacity'] !== null) {
                $this->enrollments->expireStaleHolds($class);

                if ($attributes['capacity'] < $this->enrollments->seatsTaken($class)) {
                    throw new ClassCapacityBelowEnrollmentsException;
                }
            }

            $updated = $this->classes->update($class, $attributes, $sessions);

            // A published class must keep a schedule under its enrolled learners.
            if ($updated->status === TewtoraClass::STATUS_PUBLISHED && $updated->sessions->isEmpty()) {
                throw new ClassNotPublishableException(['A published class needs at least one class day.']);
            }

            return $updated;
        });

        $this->log('class updated', $admin, $class, ['fields' => array_keys($attributes), 'sessions_replaced' => $sessions !== null]);

        return $class;
    }

    public function publish(Account $admin, TewtoraClass $class): TewtoraClass
    {
        $class = DB::transaction(function () use ($class) {
            $class = $this->classes->lockForUpdate($class)->load('sessions');
            $this->assertEditable($class);

            if ($class->status === TewtoraClass::STATUS_PUBLISHED) {
                return $class;
            }

            $this->assertPublishable($class);

            return $this->classes->update($class, [
                'status' => TewtoraClass::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);
        });

        $this->log('class published', $admin, $class);

        return $class;
    }

    /**
     * Cancels the class and every pending/confirmed enrolment in it, and
     * emails each family that had a confirmed seat. No refunds in v1: the
     * email says the team will be in touch about any payment.
     */
    public function cancel(Account $admin, TewtoraClass $class, string $reason): TewtoraClass
    {
        $affected = collect();

        $class = DB::transaction(function () use ($class, $reason, &$affected) {
            $class = $this->classes->lockForUpdate($class);
            $this->assertEditable($class);

            $affected = $this->enrollments->active($class);

            foreach ($affected as $enrollment) {
                $this->enrollments->update($enrollment, [
                    'status' => ClassEnrollment::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ]);
            }

            return $this->classes->update($class, [
                'status' => TewtoraClass::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);
        });

        $paidCount = $affected->whereNotNull('paid_at')->count();
        $this->log('class cancelled', $admin, $class, ['enrollments_cancelled' => $affected->count(), 'paid_enrollments' => $paidCount]);

        foreach ($affected->where('status', ClassEnrollment::STATUS_CANCELLED)->filter(fn ($e) => $e->confirmed_at !== null) as $enrollment) {
            $this->queueCancellationEmail($class, $enrollment);
        }

        return $class;
    }

    /**
     * Posts or changes one day's meeting link, or moves the day. Moving a day
     * renumbers the days by date. Allowed on published classes: that's how a
     * link gets posted before the day.
     *
     * @param  array{meeting_link?: ?string, starts_at?: string, ends_at?: string}  $attributes
     */
    public function updateSession(Account $admin, TewtoraClass $class, int $dayNumber, array $attributes): TewtoraClass
    {
        $this->assertEditable($class);

        $session = $class->sessions()->where('day_number', $dayNumber)->firstOrFail();
        $this->classes->updateSession($session, $attributes);

        $this->log('class day updated', $admin, $class, ['day' => $dayNumber, 'fields' => array_keys($attributes)]);

        return $class->refresh()->load('sessions');
    }

    public function replaceImage(TewtoraClass $class, UploadedFile $image): TewtoraClass
    {
        $this->assertEditable($class);

        $disk = Storage::disk(config('filesystems.class_images_disk'));
        $path = $image->storePubliclyAs(
            "class-images/{$class->public_id}",
            Str::uuid().'.'.$image->extension(),
            ['disk' => config('filesystems.class_images_disk')],
        );

        $old = $class->image_path;
        $class = $this->classes->update($class, ['image_path' => $path]);

        if ($old !== null && $old !== $path) {
            rescue(fn () => $disk->delete($old), report: false);
        }

        return $class;
    }

    public function removeImage(TewtoraClass $class): TewtoraClass
    {
        $this->assertEditable($class);

        if ($class->image_path === null) {
            return $class;
        }

        $old = $class->image_path;
        $class = $this->classes->update($class, ['image_path' => null]);
        rescue(fn () => Storage::disk(config('filesystems.class_images_disk'))->delete($old), report: false);

        return $class;
    }

    /** Scheduled (`classes:complete-finished`): published classes whose last session has ended. */
    public function completeFinished(): int
    {
        $count = 0;

        foreach ($this->classes->finished() as $class) {
            $this->classes->update($class, ['status' => TewtoraClass::STATUS_COMPLETED, 'completed_at' => now()]);
            $count++;
        }

        return $count;
    }

    private function assertEditable(TewtoraClass $class): void
    {
        if (in_array($class->status, [TewtoraClass::STATUS_CANCELLED, TewtoraClass::STATUS_COMPLETED], true)) {
            throw new ClassNotEditableException;
        }
    }

    /**
     * A meeting link isn't required to publish: links can be posted per day,
     * closer to the time (the frontend shows "posted here before then").
     */
    private function assertPublishable(TewtoraClass $class): void
    {
        $problems = [];

        if ($class->sessions->isEmpty()) {
            $problems[] = 'Add at least one class day.';
        } elseif (! $class->ends_at?->isFuture()) {
            $problems[] = 'Every class day is in the past.';
        }

        if ($problems !== []) {
            throw new ClassNotPublishableException($problems);
        }
    }

    private function queueCancellationEmail(TewtoraClass $class, ClassEnrollment $enrollment): void
    {
        try {
            $email = $this->auth->accountEmail($enrollment->payer_account_id);

            if ($email !== null) {
                Mail::to($email)->queue(new ClassCancelled($class, $enrollment));
            }
        } catch (Throwable $e) {
            rescue(fn () => Log::channel('core')->error('class cancellation email could not be queued', [
                'class_id' => $class->public_id,
                'enrollment_id' => $enrollment->public_id,
                'exception' => $e,
            ]), report: false);
        }
    }

    private function log(string $message, Account $admin, TewtoraClass $class, array $extra = []): void
    {
        rescue(fn () => Log::channel('core')->info($message, [
            'class_id' => $class->public_id,
            'actor_account_id' => $admin->public_id,
            'status' => $class->status,
            ...$extra,
        ]), report: false);
    }
}
