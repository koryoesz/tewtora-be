<?php

namespace App\Shared\Logging;

use App\Shared\Logging\Models\AuditLogEntry;
use Illuminate\Support\Facades\Auth;

/**
 * backend-engineering-standards.md §7: writes to the append-only
 * auth.audit_log on every write to learner_profiles/payments/
 * teachers.verification_status, and on every admin-facing read
 * (docs/api-contract.md §14-18: "reads are logged exactly like writes").
 * Called explicitly from services/controllers rather than as a model
 * observer — an observer would fire on every write including ones with no
 * admin/actor context (queue jobs, seeders), and "viewed" audit entries
 * have no model write to hook at all.
 */
trait Auditable
{
    protected function audit(
        string $action,
        string $subjectType,
        string $subjectId,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
    ): AuditLogEntry {
        return AuditLogEntry::create([
            'actor_account_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'reason' => $reason,
            'before_state' => $before,
            'after_state' => $after,
        ]);
    }
}
