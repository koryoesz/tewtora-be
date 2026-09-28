<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\PricingSetting;
use Illuminate\Database\Eloquent\Collection;

class EloquentPricingSettingRepository implements PricingSettingRepositoryInterface
{
    public function all(): Collection
    {
        return PricingSetting::orderBy('format')->get();
    }
}
