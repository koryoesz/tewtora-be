<?php

namespace App\Domains\Auth\Repositories;

use App\Domains\Auth\Models\Account;

class EloquentAccountRepository implements AccountRepositoryInterface
{
    public function findByEmail(string $email): ?Account
    {
        return Account::where('email', $email)->first();
    }

    public function findByPublicId(string $publicId): ?Account
    {
        return Account::where('public_id', $publicId)->first();
    }

    public function create(array $data): Account
    {
        return Account::create($data);
    }
}
