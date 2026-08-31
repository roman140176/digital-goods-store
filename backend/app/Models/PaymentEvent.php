<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Журнал событий платёжной системы. event_id первичным ключом — этим и
 * достигается идемпотентность: повторная доставка не проходит вставку.
 *
 * @property string $event_id
 * @property string $order_id
 * @property string $status
 * @property int|null $amount_minor
 * @property array $payload
 * @property string|null $outcome
 */
class PaymentEvent extends Model
{
    protected $table = 'payment_events';

    protected $primaryKey = 'event_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'amount_minor' => 'integer',
        'provider_created_at' => 'immutable_datetime',
        'received_at' => 'immutable_datetime',
        'processed_at' => 'immutable_datetime',
    ];
}
