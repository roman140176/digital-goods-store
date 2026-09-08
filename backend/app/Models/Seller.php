<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $rating
 */
class Seller extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rating' => 'decimal:1',
    ];
}
