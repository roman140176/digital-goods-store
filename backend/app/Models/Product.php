<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $sku
 * @property string $name
 * @property string $type
 * @property string $currency
 * @property string|null $image
 */
class Product extends Model
{
    protected $primaryKey = 'sku';

    protected $keyType = 'string';

    public $incrementing = false;

    // Цена сюда больше не пишется: она принадлежит предложению продавца
    // (App\Models\Offer::price_minor). products.price_minor удалена
    // миграцией 2026_09_08_000600.
    protected $fillable = ['sku', 'name', 'type', 'currency', 'image'];
}
