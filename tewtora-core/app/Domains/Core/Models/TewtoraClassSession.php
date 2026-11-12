<?php

namespace App\Domains\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One class day of a TewtoraClass ("Day 3 of 12"). Unrelated to core.sessions
 * (1-on-1 plan lessons). meeting_link overrides the class-wide link for this
 * day; meetingUrl() resolves which one applies.
 */
class TewtoraClassSession extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_LIVE = 'live';

    public const STATUS_COMPLETED = 'completed';

    protected $table = 'core.tewtora_class_sessions';

    protected $fillable = [
        'tewtora_class_id',
        'day_number',
        'starts_at',
        'ends_at',
        'meeting_link',
    ];

    protected function casts(): array
    {
        return [
            'day_number' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function tewtoraClass()
    {
        return $this->belongsTo(TewtoraClass::class, 'tewtora_class_id');
    }

    public function status(?CarbonInterface $now = null): string
    {
        $now ??= now();

        return match (true) {
            $this->ends_at->lte($now) => self::STATUS_COMPLETED,
            $this->starts_at->lte($now) => self::STATUS_LIVE,
            default => self::STATUS_UPCOMING,
        };
    }

    /** This day's link, else the class-wide one, else null (not posted yet). */
    public function meetingUrl(TewtoraClass $class): ?string
    {
        return $this->meeting_link ?? $class->meeting_link;
    }

    /**
     * Families see the link from `classes.meeting_link_reveal_minutes` before
     * the start until the end of the day's class, never days in advance, so a
     * link forwarded around early is less useful.
     */
    public function linkIsRevealable(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return $now->gte($this->starts_at->copy()->subMinutes((int) config('classes.meeting_link_reveal_minutes')))
            && $now->lt($this->ends_at);
    }
}
