<?php

namespace App\Shared\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * backend-engineering-standards.md §2: generic find/create/update/delete,
 * so domain repositories only need to implement their intention-revealing
 * methods (upcomingFor, pendingVerification, ...), never raw query
 * building leaked into services.
 *
 * @template TModel of Model
 */
abstract class BaseRepository
{
    /** @return class-string<TModel> */
    abstract protected function model(): string;

    public function find(int $id): ?Model
    {
        return $this->model()::find($id);
    }

    public function findByPublicId(string $publicId): ?Model
    {
        return $this->model()::where('public_id', $publicId)->first();
    }

    public function create(array $data): Model
    {
        return $this->model()::create($data);
    }

    public function update(Model $model, array $data): Model
    {
        $model->update($data);

        return $model;
    }

    public function delete(Model $model): bool
    {
        return (bool) $model->delete();
    }
}
