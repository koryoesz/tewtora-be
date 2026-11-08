<?php

namespace App\Domains\Core\Repositories;

use App\Domains\Core\Models\TewtoraClass;
use App\Domains\Core\Models\TewtoraClassSession;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EloquentTewtoraClassRepository implements TewtoraClassRepositoryInterface
{
    /** What families may ever see. */
    private const CATALOGUE_STATUSES = [TewtoraClass::STATUS_PUBLISHED, TewtoraClass::STATUS_COMPLETED];

    public function create(array $attributes, array $sessions): TewtoraClass
    {
        return DB::transaction(function () use ($attributes, $sessions) {
            // public_id is DB-generated (DEFAULT (UUID())) — create()'s
            // in-memory model doesn't know it without a refresh.
            $class = TewtoraClass::create($attributes + $this->window($sessions))->refresh();
            $this->writeSessions($class, $sessions);

            return $class->load('sessions');
        });
    }

    public function update(TewtoraClass $class, array $attributes, ?array $sessions = null): TewtoraClass
    {
        return DB::transaction(function () use ($class, $attributes, $sessions) {
            if ($sessions !== null) {
                TewtoraClassSession::where('tewtora_class_id', $class->id)->delete();
                $this->writeSessions($class, $sessions);
                $attributes += $this->window($sessions);
            }

            if ($attributes !== []) {
                $class->update($attributes);
            }

            return $class->refresh()->load('sessions');
        });
    }

    public function updateSession(TewtoraClassSession $session, array $attributes): TewtoraClassSession
    {
        return DB::transaction(function () use ($session, $attributes) {
            $session->update($attributes);

            if (array_key_exists('starts_at', $attributes) || array_key_exists('ends_at', $attributes)) {
                $class = $session->tewtoraClass;
                $this->renumber($class);
                $class->update([
                    'starts_at' => TewtoraClassSession::where('tewtora_class_id', $class->id)->min('starts_at'),
                    'ends_at' => TewtoraClassSession::where('tewtora_class_id', $class->id)->max('ends_at'),
                ]);
            }

            return $session->refresh();
        });
    }

    public function lockForUpdate(TewtoraClass $class): TewtoraClass
    {
        return TewtoraClass::whereKey($class->id)->lockForUpdate()->firstOrFail();
    }

    public function catalogue(array $filters = []): Collection
    {
        $now = now();

        return TewtoraClass::whereIn('status', self::CATALOGUE_STATUSES)
            ->whereNotNull('starts_at')
            ->when($filters['phase'] ?? null, fn (Builder $q, string $phase) => match ($phase) {
                TewtoraClass::PHASE_UPCOMING => $q->where('status', TewtoraClass::STATUS_PUBLISHED)->where('starts_at', '>', $now),
                TewtoraClass::PHASE_ACTIVE => $q->where('status', TewtoraClass::STATUS_PUBLISHED)->where('starts_at', '<=', $now)->where('ends_at', '>', $now),
                TewtoraClass::PHASE_COMPLETED => $q->where(fn (Builder $q) => $q->where('status', TewtoraClass::STATUS_COMPLETED)->orWhere('ends_at', '<=', $now)),
            })
            ->when($filters['subject_id'] ?? null, fn ($q, $id) => $q->where('subject_id', $id))
            ->when($filters['curriculum_id'] ?? null, fn ($q, $id) => $q->where('curriculum_id', $id))
            ->when($filters['level'] ?? null, fn ($q, $level) => $q->where('level', $level))
            ->with('sessions')
            // Running and upcoming classes first (soonest first), finished ones after.
            ->orderByRaw('CASE WHEN ends_at <= ? THEN 1 ELSE 0 END', [$now])
            ->orderBy('starts_at')
            ->get();
    }

    public function sessionsBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return TewtoraClassSession::where('starts_at', '>=', $from)
            ->where('starts_at', '<', $to)
            ->whereHas('tewtoraClass', fn (Builder $q) => $q->whereIn('status', self::CATALOGUE_STATUSES))
            ->with('tewtoraClass')
            ->orderBy('starts_at')
            ->get();
    }

    public function forAdmin(?string $status = null): Collection
    {
        return TewtoraClass::when($status, fn ($q) => $q->where('status', $status))
            ->with('sessions')
            ->orderByDesc('created_at')
            ->get();
    }

    public function finished(): Collection
    {
        return TewtoraClass::where('status', TewtoraClass::STATUS_PUBLISHED)
            ->where('ends_at', '<', now())
            ->get();
    }

    /** Day numbers follow date order, so "Day 3" is always the third class day. */
    private function writeSessions(TewtoraClass $class, array $sessions): void
    {
        $sorted = collect($sessions)
            ->map(fn ($s) => ['starts_at' => Carbon::parse($s['starts_at'])->utc(), 'ends_at' => Carbon::parse($s['ends_at'])->utc(), 'meeting_link' => $s['meeting_link'] ?? null])
            ->sortBy('starts_at')
            ->values();

        foreach ($sorted as $i => $session) {
            TewtoraClassSession::create($session + ['tewtora_class_id' => $class->id, 'day_number' => $i + 1]);
        }
    }

    /**
     * Two passes because (class, day_number) is unique: park every row on a
     * high number first, then assign 1..N, so no step ever collides.
     */
    private function renumber(TewtoraClass $class): void
    {
        $sessions = TewtoraClassSession::where('tewtora_class_id', $class->id)->orderBy('starts_at')->get();

        foreach ($sessions as $i => $session) {
            $session->update(['day_number' => 10000 + $i]);
        }

        foreach ($sessions as $i => $session) {
            $session->update(['day_number' => $i + 1]);
        }
    }

    /** @return array{starts_at: ?Carbon, ends_at: ?Carbon} */
    private function window(array $sessions): array
    {
        if ($sessions === []) {
            return ['starts_at' => null, 'ends_at' => null];
        }

        return [
            'starts_at' => collect($sessions)->map(fn ($s) => Carbon::parse($s['starts_at'])->utc())->min(),
            'ends_at' => collect($sessions)->map(fn ($s) => Carbon::parse($s['ends_at'])->utc())->max(),
        ];
    }
}
