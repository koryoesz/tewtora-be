<?php

namespace App\Domains\Core\Repositories;

use Illuminate\Database\Eloquent\Collection;

interface PricingSettingRepositoryInterface
{
    public function all(): Collection;
}
