<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimal read model letting Core's policies authorize "does this account
 * own/view this learner profile" without importing Auth's LearnerProfile
 * model. See the migration's docblock for the full rationale.
 */
class LearnerAccountLink extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'core.learner_account_links';

    protected $keyType = 'int';

    protected $primaryKey = 'learner_profile_id';

    protected $fillable = [
        'learner_profile_id',
        'public_id',
        'owner_account_id',
        'linked_login_account_id',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
