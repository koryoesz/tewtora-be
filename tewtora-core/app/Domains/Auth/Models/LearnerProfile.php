<?php

namespace App\Domains\Auth\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class LearnerProfile extends Model
{
    use SoftDeletes;

    public $timestamps = true;

    protected $table = 'auth.learner_profiles';

    protected $fillable = [
        'owner_account_id',
        'linked_login_account_id',
        'profile_type',
        'full_name',
        'date_of_birth',
        'grade_level',
        'curriculum_id',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Account-owned model: scope every query to the authenticated account's
     * own/linked profiles by default, so a missing ->where() in a new
     * endpoint fails closed (CLAUDE.md "Required patterns"; backend-
     * engineering-standards.md §4). Staff/admin bypass goes through a
     * dedicated Gate, never by omitting this scope.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('ownedByAccount', function (Builder $builder) {
            $account = Auth::user();

            if ($account instanceof Account) {
                $builder->where(function (Builder $query) use ($account) {
                    $query->where('owner_account_id', $account->id)
                        ->orWhere('linked_login_account_id', $account->id);
                });
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function owner()
    {
        return $this->belongsTo(Account::class, 'owner_account_id');
    }

    public function linkedLoginAccount()
    {
        return $this->belongsTo(Account::class, 'linked_login_account_id');
    }

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class, 'curriculum_id');
    }

    public function assessments()
    {
        return $this->hasMany(Assessment::class, 'learner_profile_id');
    }
}
