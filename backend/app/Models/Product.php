<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $sku
 * @property string $name
 * @property string $type
 * @property int $price_minor
 * @property string $currency
 * @property string|null $image
 */
class Product extends Model
{
    protected $primaryKey = 'sku';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['sku', 'name', 'type', 'price_minor', 'currency', 'image'];

    protected $casts = ['price_minor' => 'integer'];
}
