<?php

namespace App\Domains\Recommendation\Exceptions;

use App\Shared\Exceptions\AppException;

/** backend-engineering-standards.md §3: accept/decline called on a match not in 'proposed'. */
class MatchAlreadyDecidedException extends AppException
{
    public function __construct(private readonly int $matchId, private readonly string $currentStatus)
    {
        parent::__construct("This match has already been {$currentStatus}.");
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'match_already_decided';
    }

    public function context(): array
    {
        return ['match_id' => $this->matchId, 'current_status' => $this->currentStatus];
    }
}
