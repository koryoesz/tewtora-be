<?php

namespace App\Domains\Auth\Contracts;

/**
 * An account as other domains may see it — see AuthDirectory. `name` is the
 * account's own learner profile name (an independent student); null for a
 * parent, since auth.accounts has no name column.
 */
final readonly class AccountRef
{
    public function __construct(
        public int $id,
        public string $publicId,
        public ?string $email,
        public ?string $name,
    ) {}
}
