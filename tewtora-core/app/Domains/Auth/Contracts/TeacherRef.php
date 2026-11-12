<?php

namespace App\Domains\Auth\Contracts;

/** A teacher as other domains may see it — see AuthDirectory. */
final readonly class TeacherRef
{
    public function __construct(
        public int $id,
        public string $publicId,
        public int $accountId,
        public ?string $fullName,
    ) {}
}
