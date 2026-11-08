<?php

namespace App\Domains\Core\Http\Requests;

/**
 * PATCH /internal/classes/{id}. Same fields as create, all optional; only
 * what's sent changes. `schedule` or `sessions`, when sent, replaces every
 * class day. Business rules (price frozen once anyone enrolled, capacity
 * floor, a published class keeps its days) are enforced in
 * TewtoraClassService. The cover image and single days have their own endpoints.
 */
class UpdateTewtoraClassRequest extends StoreTewtoraClassRequest
{
    public function rules(): array
    {
        return collect(['title' => ['string', 'min:3', 'max:160'], ...self::detailRules()])
            ->map(fn (array $rules, string $field) => str_contains($field, '.') ? $rules : ['sometimes', ...$rules])
            ->all();
    }
}
