<?php

namespace App\Domains\Auth\Contracts;

/** A curriculum as other domains may see it — see AuthDirectory. */
final readonly class CurriculumRef
{
    public function __construct(
        public int $id,
        public string $code,
        public string $displayName,
    ) {}
}
