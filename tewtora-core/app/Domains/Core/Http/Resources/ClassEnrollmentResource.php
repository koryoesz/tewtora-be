<?php

namespace App\Domains\Core\Http\Resources;

use App\Domains\Auth\Contracts\LearnerRef;
use App\Domains\Core\Models\ClassEnrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One learner's seat. checkout_url and hold_expires_at only while the seat
 * hold is live. Meeting links are not here: they're per class day, on
 * GET /classes/{id}/sessions, and only revealed close to each day.
 *
 * @mixin ClassEnrollment
 */
class ClassEnrollmentResource extends JsonResource
{
    public function __construct(ClassEnrollment $enrollment, private readonly ?LearnerRef $learner = null)
    {
        parent::__construct($enrollment);
    }

    public function toArray(Request $request): array
    {
        $class = $this->tewtoraClass;
        $tz = config('classes.display_timezone');
        $pendingLive = $this->status === ClassEnrollment::STATUS_PENDING && $this->hold_expires_at?->isFuture();

        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'class' => [
                'id' => $class->public_id,
                'title' => $class->title,
                'art' => $class->art,
                'image_url' => $class->imageUrl(),
                'status' => $class->phase(),
                'cancelled' => $class->status === 'cancelled',
                'starts_on' => $class->starts_at?->setTimezone($tz)->toDateString(),
                'ends_on' => $class->ends_at?->setTimezone($tz)->toDateString(),
            ],
            'learner' => $this->learner ? ['id' => $this->learner->publicId, 'name' => $this->learner->fullName] : null,
            'amount_minor' => $this->amount_minor,
            'currency_code' => $this->currency_code,
            'is_free' => $this->amount_minor === 0,
            'checkout_url' => $this->when($pendingLive, $this->checkout_url),
            'hold_expires_at' => $this->when($pendingLive, fn () => $this->hold_expires_at->toIso8601ZuluString()),
            'paid_at' => $this->paid_at?->toIso8601ZuluString(),
            'confirmed_at' => $this->confirmed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
