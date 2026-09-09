<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $order_id
 * @property int $discount_minor
 * @property CarbonInterface|null $released_at
 */
class PromoRedemption extends Model
{
    protected $guarded = [];

    protected $casts = [
        'discount_minor' => 'integer',
        'released_at' => 'immutable_datetime',
    ];
}
