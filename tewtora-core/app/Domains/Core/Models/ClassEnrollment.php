<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One learner's seat in a TewtoraClass (see 2024_02_01_000110's docblock).
 * learner_profile_id/payer_account_id are cross-schema identities into
 * auth.*; payment_id is the Payment service's public_id.
 *
 * pending_payment -> confirmed (paid, or free)
 *                 -> expired   (seat hold ran out before payment)
 *                 -> cancelled (class cancelled)
 * A late payment can still move expired -> confirmed: money taken always
 * means a seat (ClassEnrollmentService::confirmPayment).
 */
class ClassEnrollment extends Model
{
    public const STATUS_PENDING = 'pending_payment';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'core.class_enrollments';

    protected $fillable = [
        'tewtora_class_id',
        'learner_profile_id',
        'payer_account_id',
        'status',
        'amount_minor',
        'currency_code',
        'payment_id',
        'provider_reference',
        'checkout_url',
        'hold_expires_at',
        'paid_at',
        'confirmed_at',
        'expired_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'tewtora_class_id' => 'integer',
            'learner_profile_id' => 'integer',
            'payer_account_id' => 'integer',
            'amount_minor' => 'integer',
            'hold_expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'expired_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function tewtoraClass()
    {
        return $this->belongsTo(TewtoraClass::class, 'tewtora_class_id');
    }

    /** A pending enrolment whose seat hold hasn't run out yet. */
    public function holdsSeat(): bool
    {
        return $this->status === self::STATUS_CONFIRMED
            || ($this->status === self::STATUS_PENDING && $this->hold_expires_at?->isFuture());
    }
}
