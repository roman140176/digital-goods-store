<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $offer_id
 * @property string $state
 * @property string|null $reserved_order_id
 * @property \Carbon\CarbonInterface|null $reserved_until
 * @property \Carbon\CarbonInterface|null $sold_at
 */
class StockUnit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'reserved_until' => 'immutable_datetime',
        'sold_at' => 'immutable_datetime',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class, 'offer_id', 'id');
    }
}
