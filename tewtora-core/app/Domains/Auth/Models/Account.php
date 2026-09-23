<?php

namespace App\Domains\Auth\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * The identity/login model. Token abilities are assigned per role at login
 * (backend-engineering-standards.md §4), not fixed on the model.
 */
class Account extends Authenticatable
{
    use HasApiTokens;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'auth.accounts';

    protected $fillable = [
        'email',
        'phone',
        'password_hash',
        'account_type',
        'email_verified_at',
    ];

    protected $hidden = [
        'password_hash',
        'id',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /** Argon2id hash column; see backend-engineering-standards.md §4. */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function learnerProfiles()
    {
        return $this->hasMany(LearnerProfile::class, 'owner_account_id');
    }

    public function teacher()
    {
        return $this->hasOne(Teacher::class, 'account_id');
    }
}
