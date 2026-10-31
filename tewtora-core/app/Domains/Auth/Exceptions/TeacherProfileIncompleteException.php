<?php

namespace App\Domains\Auth\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * A self-signed-up teacher starts with only a name (see
 * 2024_02_01_000108) — approving them before onboarding fills in the rest
 * would put a card with no rate/format/experience into the browse list.
 */
class TeacherProfileIncompleteException extends AppException
{
    /** @param  list<string>  $missing  column names still null */
    public function __construct(private readonly int $teacherId, private readonly array $missing)
    {
        parent::__construct("This teacher hasn't finished their profile yet, so they can't be approved.");
    }

    public function statusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'teacher_profile_incomplete';
    }

    public function context(): array
    {
        return ['teacher_id' => $this->teacherId, 'missing' => $this->missing];
    }
}
