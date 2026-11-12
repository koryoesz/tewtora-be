<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A course Tewtora runs itself (see 2024_02_01_000110's docblock). Named
 * TewtoraClass because `Class` is reserved in PHP. subject_id/curriculum_id/
 * teacher_id/teacher_account_id are cross-schema identities into auth.* —
 * resolved through AuthDirectory, never as Eloquent relationships.
 *
 * Two different "statuses", deliberately named apart:
 * - status: the admin lifecycle (draft, published, cancelled, completed);
 * - phase(): what families see (upcoming, active, completed), computed from
 *   the schedule so a rescheduled class reads correctly without anyone
 *   updating a column.
 */
class TewtoraClass extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const PHASE_UPCOMING = 'upcoming';

    public const PHASE_ACTIVE = 'active';

    public const PHASE_COMPLETED = 'completed';

    public const FORMATS = ['small_group', 'live_class'];

    /** The frontend's built-in illustrations (tewtora-fe components/classes/ClassArt). */
    public const ARTS = ['maths', 'english', 'science', 'coding'];

    protected $table = 'core.tewtora_classes';

    protected $fillable = [
        'title',
        'description',
        'subject_id',
        'curriculum_id',
        'level',
        'format',
        'art',
        'teacher_id',
        'teacher_account_id',
        'price_minor',
        'currency_code',
        'capacity',
        'meeting_link',
        'image_path',
        'status',
        'starts_at',
        'ends_at',
        'rating_avg',
        'rating_count',
        'published_at',
        'cancelled_at',
        'cancellation_reason',
        'completed_at',
        'created_by_account_id',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'curriculum_id' => 'integer',
            'teacher_id' => 'integer',
            'teacher_account_id' => 'integer',
            'price_minor' => 'integer',
            'capacity' => 'integer',
            'rating_avg' => 'float',
            'rating_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function sessions()
    {
        return $this->hasMany(TewtoraClassSession::class, 'tewtora_class_id')->orderBy('day_number');
    }

    public function enrollments()
    {
        return $this->hasMany(ClassEnrollment::class, 'tewtora_class_id');
    }

    public function isFree(): bool
    {
        return $this->price_minor === 0;
    }

    public function isUncapped(): bool
    {
        return $this->capacity === null;
    }

    public function phase(): string
    {
        if ($this->status === self::STATUS_COMPLETED || $this->ends_at?->isPast()) {
            return self::PHASE_COMPLETED;
        }

        return $this->starts_at === null || $this->starts_at->isFuture() ? self::PHASE_UPCOMING : self::PHASE_ACTIVE;
    }

    /**
     * Enrolment stays open until the class's last day has finished, so a
     * family can join a course that's already running (the frontend shows
     * "Enrol" on active classes). The price is the same whenever they join.
     */
    public function isOpenForEnrollment(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->ends_at !== null
            && $this->ends_at->isFuture();
    }

    public function imageUrl(): ?string
    {
        return $this->image_path !== null
            ? Storage::disk(config('filesystems.class_images_disk'))->url($this->image_path)
            : null;
    }
}
