<?php

namespace App\Domains\Auth\Http\Resources;

use App\Domains\Auth\Models\LearnerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isMinor = $this->account_type === 'child';

        return [
            'id' => $this->public_id,
            'role' => $this->account_type,
            'email' => $this->email,
            'is_minor' => $isMinor,
            'guardian_label' => $isMinor ? $this->guardianEmail() : null,
        ];
    }

    /** docs/api-contract.md §18: "the handle with care — only X and safeguarding staff" panel. */
    private function guardianEmail(): ?string
    {
        $owner = LearnerProfile::withoutGlobalScopes()
            ->where('linked_login_account_id', $this->id)
            ->with('owner')
            ->first()?->owner;

        return $owner?->email;
    }
}
