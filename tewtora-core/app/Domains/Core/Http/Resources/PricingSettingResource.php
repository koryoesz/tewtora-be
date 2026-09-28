<?php

namespace App\Domains\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PricingSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'format' => $this->format,
            'rate_minor' => $this->rate_minor,
            'currency_code' => $this->currency_code,
        ];
    }
}
