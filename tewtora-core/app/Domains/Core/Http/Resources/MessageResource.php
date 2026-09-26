<?php

namespace App\Domains\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            // Never the sender's account id — a viewer only needs to know
            // whether a message is theirs and who sent it in role terms.
            'sender_role' => $this->sender_role,
            'is_own' => $this->sender_account_id === $request->user()?->id,
            'body' => $this->body,
            'redacted' => $this->redacted,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
