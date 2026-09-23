<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

class PlanGoal extends Model
{
    public $timestamps = false;

    protected $table = 'core.plan_goals';

    protected $fillable = [
        'plan_id',
        'label',
        'pct',
    ];

    protected function casts(): array
    {
        return [
            'pct' => 'decimal:2',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
