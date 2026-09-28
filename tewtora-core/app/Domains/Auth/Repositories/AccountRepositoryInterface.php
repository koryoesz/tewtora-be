<?php

namespace App\Domains\Auth\Repositories;

use App\Domains\Auth\Models\Account;

interface AccountRepositoryInterface
{
    public function findByEmail(string $email): ?Account;

    public function findByUsername(string $username): ?Account;

    public function findByPublicId(string $publicId): ?Account;

    public function create(array $data): Account;
}
