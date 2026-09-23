<?php

namespace App\Shared\Logging\Models;

use App\Domains\Auth\Models\Account;
use Illuminate\Database\Eloquent\Model;

/**
 * Cross-cutting: written by every domain's admin-facing reads/writes, not
 * owned by any one Domains/{X} folder (AGENTS.md: "Only app/Shared is
 * exempt, and only for genuinely cross-cutting concerns"). See
 * docs/api-gap-analysis.md §18 and the migration's docblock for why the
 * underlying table lives in the `auth` schema specifically. The writing
 * side (an Auditable trait/observer) is deferred to the next
 * implementation pass — this is the model shell only.
 */
class AuditLogEntry extends Model
{
    public $timestamps = false;

    protected $table = 'auth.audit_log';

    protected $fillable = [
        'actor_account_id',
        'action',
        'subject_type',
        'subject_id',
        'reason',
        'before_state',
        'after_state',
    ];

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function actor()
    {
        return $this->belongsTo(Account::class, 'actor_account_id');
    }
}
