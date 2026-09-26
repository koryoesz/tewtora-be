<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimal read model letting Core's policies authorize "is this account
 * this teacher" without importing Auth's Teacher model.
 */
class TeacherAccountLink extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'core.teacher_account_links';

    protected $keyType = 'int';

    protected $primaryKey = 'teacher_id';

    protected $fillable = [
        'teacher_id',
        'public_id',
        'account_id',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
