<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Delivery\DeliveryState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $order_id
 * @property string $request_id
 * @property DeliveryState $state
 * @property int $attempts
 * @property string|null $supplier
 * @property string|null $code
 * @property string|null $last_error
 * @property CarbonInterface|null $locked_until
 */
class Delivery extends Model
{
    protected $table = 'deliveries';

    protected $guarded = [];

    protected $casts = [
        'state' => DeliveryState::class,
        'attempts' => 'integer',
        'locked_until' => 'immutable_datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    /** Ключ запроса к поставщику детерминирован и живёт весь срок заказа. */
    public static function requestIdFor(string $orderId): string
    {
        return 'req_'.$orderId;
    }
}
