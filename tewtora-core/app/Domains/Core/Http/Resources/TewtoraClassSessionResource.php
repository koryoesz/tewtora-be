<?php

namespace App\Domains\Core\Http\Resources;

use App\Domains\Core\Models\TewtoraClass;
use App\Domains\Core\Models\TewtoraClassSession;
use App\Domains\Core\Policies\TewtoraClassPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One class day (tewtora-fe docs/needed-endpoints-tewtora-classes.md §2):
 * `day` N of the class's total, Lagos-offset times, a status, and
 * `meeting_url` — null unless the viewer may see it now:
 * - LINK_ALWAYS (admin, class teacher): whenever one is posted;
 * - LINK_WINDOW (confirmed seat): only from the reveal window before the
 *   start until the end (TewtoraClassSession::linkIsRevealable);
 * - otherwise never.
 * The frontend's Join button opens 10 minutes before `starts_at` on its own.
 * `class` is included for the cross-class week schedule.
 *
 * @mixin TewtoraClassSession
 */
class TewtoraClassSessionResource extends JsonResource
{
    public function __construct(
        TewtoraClassSession $session,
        private readonly TewtoraClass $class,
        private readonly ?string $linkAccess,
        private readonly ?array $classSummary = null,
    ) {
        parent::__construct($session);
    }

    public function toArray(Request $request): array
    {
        $tz = config('classes.display_timezone');
        $visible = match ($this->linkAccess) {
            TewtoraClassPolicy::LINK_ALWAYS => true,
            TewtoraClassPolicy::LINK_WINDOW => $this->linkIsRevealable(),
            default => false,
        };

        return [
            'id' => $this->public_id,
            'day' => $this->day_number,
            'date' => $this->starts_at->copy()->setTimezone($tz)->toDateString(),
            'starts_at' => $this->starts_at->copy()->setTimezone($tz)->toIso8601String(),
            'ends_at' => $this->ends_at->copy()->setTimezone($tz)->toIso8601String(),
            'status' => $this->status(),
            'meeting_url' => $visible ? $this->meetingUrl($this->class) : null,
            'class' => $this->when($this->classSummary !== null, fn () => $this->classSummary),
        ];
    }
}
