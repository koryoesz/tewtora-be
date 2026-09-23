<?php

namespace App\Shared\Logging\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Payment's own audit trail — a separate table from tewtora-core's
 * auth.audit_log, since Payment is a fully separate service/database. See
 * the migration's docblock. actor_account_id is a cross-service reference
 * into Auth's database — intentionally not an Eloquent relationship. The
 * writing side (an Auditable trait/observer) is deferred to the next
 * implementation pass — this is the model shell only.
 */
class AuditLogEntry extends Model
{
    public $timestamps = false;

    protected $table = 'payment_audit_log';

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
}
