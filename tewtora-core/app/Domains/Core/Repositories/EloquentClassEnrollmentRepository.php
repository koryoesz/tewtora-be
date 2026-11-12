<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\ClassEnrollment;
use App\Domains\Core\Models\TewtoraClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class EloquentClassEnrollmentRepository implements ClassEnrollmentRepositoryInterface
{
    public function create(array $attributes): ClassEnrollment
    {
        // public_id is DB-generated (DEFAULT (UUID())) — create()'s
        // in-memory model doesn't know it without a refresh.
        return ClassEnrollment::create($attributes)->refresh();
    }

    public function update(ClassEnrollment $enrollment, array $attributes): ClassEnrollment
    {
        $enrollment->update($attributes);

        return $enrollment;
    }

    public function lockForUpdate(ClassEnrollment $enrollment): ClassEnrollment
    {
        return ClassEnrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
    }

    public function findByPublicId(string $publicId): ?ClassEnrollment
    {
        return ClassEnrollment::where('public_id', $publicId)->first();
    }

    public function findByPaymentReference(string $reference): ?ClassEnrollment
    {
        // payment_id is uniquely indexed and is what Core sends Paystack as the
        // reference, so it almost always hits; provider_reference covers a
        // provider that assigns its own.
        return ClassEnrollment::where('payment_id', $reference)->first()
            ?? ClassEnrollment::where('provider_reference', $reference)->first();
    }

    public function eachWithPayment(callable $callback, int $size = 200): void
    {
        ClassEnrollment::whereNotNull('payment_id')->with('tewtoraClass')->lazyById($size)->each($callback);
    }

    public function activeFor(TewtoraClass $class, int $learnerProfileId): ?ClassEnrollment
    {
        return ClassEnrollment::where('tewtora_class_id', $class->id)
            ->where('learner_profile_id', $learnerProfileId)
            ->whereIn('status', [ClassEnrollment::STATUS_PENDING, ClassEnrollment::STATUS_CONFIRMED])
            ->first();
    }

    public function seatsTaken(TewtoraClass $class): int
    {
        return $this->seatsTakenFor([$class->id])[$class->id] ?? 0;
    }

    public function seatsTakenFor(array $classIds): array
    {
        if ($classIds === []) {
            return [];
        }

        return ClassEnrollment::whereIn('tewtora_class_id', $classIds)
            ->where(fn (Builder $q) => $this->holdingSeat($q))
            ->groupBy('tewtora_class_id')
            ->selectRaw('tewtora_class_id, COUNT(*) AS taken')
            ->pluck('taken', 'tewtora_class_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    public function expireStaleHolds(?TewtoraClass $class = null): int
    {
        return ClassEnrollment::where('status', ClassEnrollment::STATUS_PENDING)
            ->where('hold_expires_at', '<=', now())
            ->when($class, fn ($q) => $q->where('tewtora_class_id', $class->id))
            ->update(['status' => ClassEnrollment::STATUS_EXPIRED, 'expired_at' => now()]);
    }

    public function active(TewtoraClass $class): Collection
    {
        return ClassEnrollment::where('tewtora_class_id', $class->id)
            ->whereIn('status', [ClassEnrollment::STATUS_PENDING, ClassEnrollment::STATUS_CONFIRMED])
            ->orderBy('created_at')
            ->get();
    }

    public function visibleTo(int $accountId, array $learnerIds): Collection
    {
        return ClassEnrollment::where(fn (Builder $q) => $q->where('payer_account_id', $accountId)
            ->when($learnerIds !== [], fn ($q) => $q->orWhereIn('learner_profile_id', $learnerIds)))
            ->with('tewtoraClass.sessions')
            ->orderByDesc('created_at')
            ->get();
    }

    public function hasConfirmedFor(TewtoraClass $class, array $learnerIds, int $payerAccountId): bool
    {
        return ClassEnrollment::where('tewtora_class_id', $class->id)
            ->where('status', ClassEnrollment::STATUS_CONFIRMED)
            ->where(fn (Builder $q) => $q->where('payer_account_id', $payerAccountId)
                ->when($learnerIds !== [], fn ($q) => $q->orWhereIn('learner_profile_id', $learnerIds)))
            ->exists();
    }

    public function existsFor(TewtoraClass $class): bool
    {
        return ClassEnrollment::where('tewtora_class_id', $class->id)->exists();
    }

    public function hasAnyFor(TewtoraClass $class, array $learnerIds, int $payerAccountId): bool
    {
        return ClassEnrollment::where('tewtora_class_id', $class->id)
            ->where(fn (Builder $q) => $q->where('payer_account_id', $payerAccountId)
                ->when($learnerIds !== [], fn ($q) => $q->orWhereIn('learner_profile_id', $learnerIds)))
            ->exists();
    }

    public function confirmedLearnersByClass(array $classIds, array $learnerIds): array
    {
        if ($classIds === [] || $learnerIds === []) {
            return [];
        }

        return ClassEnrollment::whereIn('tewtora_class_id', $classIds)
            ->whereIn('learner_profile_id', $learnerIds)
            ->where('status', ClassEnrollment::STATUS_CONFIRMED)
            ->get(['tewtora_class_id', 'learner_profile_id'])
            ->groupBy('tewtora_class_id')
            ->map(fn ($rows) => $rows->pluck('learner_profile_id')->all())
            ->all();
    }

    public function classesWithConfirmedSeatFor(array $classIds, array $learnerIds, int $payerAccountId): array
    {
        if ($classIds === []) {
            return [];
        }

        return ClassEnrollment::whereIn('tewtora_class_id', $classIds)
            ->where('status', ClassEnrollment::STATUS_CONFIRMED)
            ->where(fn (Builder $q) => $q->where('payer_account_id', $payerAccountId)
                ->when($learnerIds !== [], fn ($q) => $q->orWhereIn('learner_profile_id', $learnerIds)))
            ->distinct()
            ->pluck('tewtora_class_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function holdingSeat(Builder $query): Builder
    {
        return $query->where('status', ClassEnrollment::STATUS_CONFIRMED)
            ->orWhere(fn (Builder $q) => $q->where('status', ClassEnrollment::STATUS_PENDING)
                ->where('hold_expires_at', '>', now()));
    }
}
