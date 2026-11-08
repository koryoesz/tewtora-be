<?php

namespace App\Domains\Auth\Contracts;

/** A learner profile as other domains may see it — see AuthDirectory. */
final readonly class LearnerRef
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $fullName,
        public int $ownerAccountId,
        public ?int $linkedLoginAccountId,
    ) {}
}
