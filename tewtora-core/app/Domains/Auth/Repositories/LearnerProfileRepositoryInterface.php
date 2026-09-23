<?php

namespace App\Domains\Auth\Repositories;

use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\LearnerProfile;
use Illuminate\Database\Eloquent\Collection;

interface LearnerProfileRepositoryInterface
{
    public function find(int $id): ?LearnerProfile;

    public function findByPublicId(string $publicId): ?LearnerProfile;

    /** All learners under this account — children + self, if set up. */
    public function forAccount(Account $account): Collection;

    public function create(array $data): LearnerProfile;

    public function update(LearnerProfile $profile, array $data): LearnerProfile;

    public function archive(LearnerProfile $profile): void;
}
