<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * party_account_id is a cross-schema identity (auth.accounts) —
 * intentionally not an Eloquent relationship.
 */
class MoveApproval extends Model
{
    public $timestamps = false;

    protected $table = 'core.move_approvals';

    protected $fillable = [
        'move_request_id',
        'party_account_id',
        'party_label',
        'role',
        'state',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
        ];
    }

    public function moveRequest()
    {
        return $this->belongsTo(MoveRequest::class, 'move_request_id');
    }
}
