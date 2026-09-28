<?php

namespace App\Domains\Core\Http\Controllers;

use App\Domains\Core\Http\Resources\PricingSettingResource;
use App\Domains\Core\Repositories\PricingSettingRepositoryInterface;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Indicative pre-match pricing for the assessment budget/schedule step —
 * a real teacher's price_per_session_minor is only known post-match.
 * Platform-wide and uniform per format today, not per-teacher/subject;
 * see database/seeders/PricingSettingsSeeder.php for the current values.
 */
class PricingController
{
    public function __construct(
        private readonly PricingSettingRepositoryInterface $pricing,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return PricingSettingResource::collection($this->pricing->all());
    }
}
