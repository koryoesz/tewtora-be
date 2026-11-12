<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Core's read model of a family's payment — see the 2024_02_01_000111
 * migration. Written only by PaymentViewService; status only ever moves
 * forward (pending -> paid|failed, failed -> paid, paid -> refunded), so a redelivered or
 * out-of-order event can't undo an outcome.
 */
class PaymentView extends Model
{
    public const STATUS_PAID = 'paid';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [self::STATUS_PAID, self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_REFUNDED];

    public const PURPOSE_CLASS_ENROLLMENT = 'class_enrollment';

    public const PURPOSE_PLAN = 'plan';

    protected $table = 'core.payment_view';

    protected $fillable = [
        'payment_id',
        'purpose',
        'purpose_ref',
        'payer_account_id',
        'payer_account_public_id',
        'payer_name',
        'payer_email',
        'learner_profile_id',
        'learner_name',
        'for_label',
        'reference',
        'amount_minor',
        'currency_code',
        'status',
        'channel',
        'paid_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payer_account_id' => 'integer',
            'learner_profile_id' => 'integer',
            'amount_minor' => 'integer',
            'paid_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** Where a status may go next; a status may always be re-applied to refresh details. */
    public function canBecome(string $status): bool
    {
        return $status === $this->status || match ($this->status) {
            self::STATUS_PENDING => true,
            // A declined card, then a retry on the same checkout that went through.
            self::STATUS_FAILED => $status === self::STATUS_PAID,
            self::STATUS_PAID => $status === self::STATUS_REFUNDED,
            default => false,
        };
    }
}
