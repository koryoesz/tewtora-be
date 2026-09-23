<?php

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Exceptions\VerificationChecksIncompleteException;
use App\Domains\Auth\Models\Teacher;
use App\Shared\Logging\Auditable;
use Illuminate\Support\Facades\DB;

/**
 * docs/api-contract.md §14. Reuses TeacherVerificationCheck's own state
 * vocabulary (pending|in_review|approved|rejected, from §3) rather than
 * the contract's separately-worded VerificationCheck.state
 * (confirmed|outstanding|problem) — the two sections describe the same
 * underlying concept with different words; introducing a second parallel
 * state machine for the same data wasn't worth the duplication. 'approved'
 * here is what the contract calls 'confirmed'.
 */
class VerificationService
{
    use Auditable;

    /** docs/api-contract.md §14: query: state (open|waiting|done). */
    public function applications(?string $state = null)
    {
        $query = Teacher::with('verificationChecks')->whereIn('verification_status', ['pending', 'approved', 'rejected']);

        return match ($state) {
            'open' => $query->where('verification_status', 'pending')->get(),
            'done' => $query->whereIn('verification_status', ['approved', 'rejected'])->get(),
            default => $query->get(),
        };
    }

    public function viewApplication(Teacher $teacher): Teacher
    {
        $teacher->load('verificationChecks');

        $this->audit('viewed', 'teacher_verification_application', (string) $teacher->public_id);

        return $teacher;
    }

    public function decide(Teacher $teacher, string $decision, string $note): Teacher
    {
        return DB::transaction(function () use ($teacher, $decision, $note) {
            if ($decision === 'approved') {
                $allConfirmed = $teacher->verificationChecks()->where('state', '!=', 'approved')->doesntExist();

                if (! $allConfirmed) {
                    throw new VerificationChecksIncompleteException($teacher->id);
                }
            }

            $before = $teacher->only('verification_status');

            $teacher->update([
                'verification_status' => $decision === 'needs_more' ? 'pending' : $decision,
            ]);

            $this->audit(
                $decision === 'approved' ? 'approved' : 'rejected',
                'teacher_verification_application',
                (string) $teacher->public_id,
                $note,
                $before,
                $teacher->only('verification_status'),
            );

            return $teacher;
        });
    }
}
