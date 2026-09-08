<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $product_sku
 * @property int $seller_id
 * @property string $supplier_id
 * @property int $price_minor
 * @property string $currency
 * @property string $status
 */
class Offer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'price_minor' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_sku', 'sku');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'seller_id', 'id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(StockUnit::class, 'offer_id', 'id');
    }
}
