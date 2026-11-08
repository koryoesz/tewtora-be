<?php

namespace App\Domains\Auth\Contracts;

/** A subject as other domains may see it — see AuthDirectory. */
final readonly class SubjectRef
{
    public function __construct(
        public int $id,
        public string $code,
        public string $displayName,
    ) {}
}
