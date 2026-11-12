<?php

namespace App\Domains\Core\Services;

use App\Domains\Auth\Models\Account;
use App\Domains\Core\Exceptions\ClassNotRateableYetException;
use App\Domains\Core\Models\ClassRating;
use App\Domains\Core\Models\TewtoraClass;
use App\Domains\Core\Repositories\TewtoraClassRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Families with a confirmed seat rate a class 1-5 (TewtoraClassPolicy::rate)
 * once a class day has finished; rating again replaces their rating. The
 * class's rating_avg/rating_count are recomputed in the same transaction,
 * so the catalogue never shows a stale average.
 */
class ClassRatingService
{
    public function __construct(
        private readonly TewtoraClassRepositoryInterface $classes,
    ) {}

    public function rate(Account $rater, TewtoraClass $class, int $rating, ?string $comment): TewtoraClass
    {
        if (! $class->sessions()->where('ends_at', '<=', now())->exists()) {
            throw new ClassNotRateableYetException;
        }

        return DB::transaction(function () use ($rater, $class, $rating, $comment) {
            $class = $this->classes->lockForUpdate($class);

            ClassRating::updateOrCreate(
                ['tewtora_class_id' => $class->id, 'rater_account_id' => $rater->id],
                ['rating' => $rating, 'comment' => $comment],
            );

            $stats = ClassRating::where('tewtora_class_id', $class->id)->selectRaw('AVG(rating) AS avg, COUNT(*) AS n')->first();

            return $this->classes->update($class, [
                'rating_avg' => round((float) $stats->avg, 2),
                'rating_count' => (int) $stats->n,
            ]);
        });
    }
}
