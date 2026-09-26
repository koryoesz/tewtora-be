<?php

namespace App\Domains\Auth\Repositories;

use App\Domains\Auth\Models\Account;
use App\Domains\Auth\Models\LearnerProfile;
use Illuminate\Database\Eloquent\Collection;

class EloquentLearnerProfileRepository implements LearnerProfileRepositoryInterface
{
    public function find(int $id): ?LearnerProfile
    {
        return LearnerProfile::withoutGlobalScopes()->find($id);
    }

    public function findByPublicId(string $publicId): ?LearnerProfile
    {
        return LearnerProfile::withoutGlobalScopes()->where('public_id', $publicId)->first();
    }

    public function forAccount(Account $account): Collection
    {
        return LearnerProfile::where('owner_account_id', $account->id)
            ->orWhere('linked_login_account_id', $account->id)
            ->get();
    }

    public function create(array $data): LearnerProfile
    {
        return LearnerProfile::create($data);
    }

    public function update(LearnerProfile $profile, array $data): LearnerProfile
    {
        $profile->update($data);

        return $profile;
    }

    public function archive(LearnerProfile $profile): void
    {
        $profile->delete();
    }

    public function restore(LearnerProfile $profile): void
    {
        $profile->restore();
    }
}
