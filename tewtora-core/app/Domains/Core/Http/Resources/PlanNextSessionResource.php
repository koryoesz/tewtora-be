<?php

namespace App\Domains\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanNextSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            // Only present when `plan` was eager-loaded — the teacher-side
            // aggregate (TeacherClassController) needs it to tell a
            // teacher's several plans' rows apart; the per-plan endpoint's
            // caller already has the plan in hand, so it skips the load.
            'plan_id' => $this->whenLoaded('plan', fn () => $this->plan?->public_id),
            'starts_at' => $this->scheduled_at?->toIso8601String(),
            'is_live' => $this->status === 'in_progress',
        ];
    }
}
