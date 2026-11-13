<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\ClassEnrollment;
use App\Domains\Core\Models\TewtoraClass;
use Illuminate\Database\Eloquent\Collection;

interface ClassEnrollmentRepositoryInterface
{
    public function create(array $attributes): ClassEnrollment;

    public function update(ClassEnrollment $enrollment, array $attributes): ClassEnrollment;

    /** Re-reads the enrolment with a row lock — call inside a transaction. */
    public function lockForUpdate(ClassEnrollment $enrollment): ClassEnrollment;

    public function findByPublicId(string $publicId): ?ClassEnrollment;

    /** By Payment's payment id, or the provider's reference (the `?reference=` Paystack returns with). */
    public function findByPaymentReference(string $reference): ?ClassEnrollment;

    /** Walks every enrolment that has a Payment-service payment, in id order, $size at a time. */
    public function eachWithPayment(callable $callback, int $size = 200): void;

    /** The learner's pending or confirmed enrolment in this class, if any. */
    public function activeFor(TewtoraClass $class, int $learnerProfileId): ?ClassEnrollment;

    /** Confirmed seats plus pending ones whose hold hasn't run out. */
    public function seatsTaken(TewtoraClass $class): int;

    /**
     * @param  list<int>  $classIds
     * @return array<int, int> class id => seats taken
     */
    public function seatsTakenFor(array $classIds): array;

    /** Marks pending enrolments with a run-out hold as expired; optionally for one class. Returns how many. */
    public function expireStaleHolds(?TewtoraClass $class = null): int;

    /** Every enrolment that's still pending or confirmed in this class. */
    public function active(TewtoraClass $class): Collection;

    /**
     * Enrolments an account paid for, or that belong to a learner it owns or signs in as.
     *
     * @param  list<int>  $learnerIds
     */
    public function visibleTo(int $accountId, array $learnerIds): Collection;

    /**
     * One learner's seats, for the plans page: confirmed, plus pending ones
     * whose hold hasn't run out. Newest first, with each class's days loaded.
     */
    public function seatsHeldBy(int $learnerProfileId): Collection;

    public function hasConfirmedFor(TewtoraClass $class, array $learnerIds, int $payerAccountId): bool;

    public function existsFor(TewtoraClass $class): bool;

    /** Any enrolment at all (whatever its status) by this payer or for these learners. */
    public function hasAnyFor(TewtoraClass $class, array $learnerIds, int $payerAccountId): bool;

    /**
     * For each class, which of these learners hold a confirmed seat.
     *
     * @param  list<int>  $classIds
     * @param  list<int>  $learnerIds
     * @return array<int, list<int>> class id => learner ids
     */
    public function confirmedLearnersByClass(array $classIds, array $learnerIds): array;

    /**
     * Classes (among these) where this account paid for, or one of these learners holds, a confirmed seat.
     *
     * @param  list<int>  $classIds
     * @param  list<int>  $learnerIds
     * @return list<int>
     */
    public function classesWithConfirmedSeatFor(array $classIds, array $learnerIds, int $payerAccountId): array;
}
