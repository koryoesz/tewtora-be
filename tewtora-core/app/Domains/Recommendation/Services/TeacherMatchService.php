<?php

namespace App\Domains\Recommendation\Services;

use App\Domains\Recommendation\Exceptions\MatchAlreadyDecidedException;
use App\Domains\Recommendation\Models\TeacherMatch;
use App\Domains\Recommendation\Repositories\TeacherMatchRepositoryInterface;

class TeacherMatchService
{
    public function __construct(
        private readonly TeacherMatchRepositoryInterface $matches,
    ) {}

    public function accept(TeacherMatch $match): TeacherMatch
    {
        $this->assertPending($match);

        return $this->matches->accept($match);
    }

    public function decline(TeacherMatch $match, string $reason): TeacherMatch
    {
        $this->assertPending($match);

        return $this->matches->decline($match, $reason);
    }

    private function assertPending(TeacherMatch $match): void
    {
        if ($match->status !== 'proposed') {
            throw new MatchAlreadyDecidedException($match->id, $match->status);
        }
    }
}
