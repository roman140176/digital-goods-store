<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Orders\OrderStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $sku
 * @property int $amount_minor
 * @property int $discount_minor
 * @property int $total_minor
 * @property string $currency
 * @property string|null $promo_code
 * @property OrderStatus $status
 * @property string $idempotency_key
 * @property CarbonInterface|null $last_event_at
 * @property string|null $paid_event_id
 * @property string|null $delivered_code
 * @property string|null $delivered_by
 * @property CarbonInterface|null $delivered_at
 */
class Order extends Model
{
    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'status' => OrderStatus::class,
        'amount_minor' => 'integer',
        'discount_minor' => 'integer',
        'total_minor' => 'integer',
        'last_event_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
    ];

    public function product(): HasOne
    {
        return $this->hasOne(Product::class, 'sku', 'sku');
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class, 'order_id', 'id');
    }

    public function audit(): HasMany
    {
        return $this->hasMany(OrderAudit::class, 'order_id', 'id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class, 'order_id', 'id');
    }
}
