<?php

namespace App\Domains\Core\Models;

use Illuminate\Database\Eloquent\Model;

/** A paying family's 1-5 rating of a Tewtora class; one per account per class. rater_account_id is a cross-schema identity. */
class ClassRating extends Model
{
    protected $table = 'core.class_ratings';

    protected $fillable = [
        'tewtora_class_id',
        'rater_account_id',
        'rating',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'rater_account_id' => 'integer',
        ];
    }
}
