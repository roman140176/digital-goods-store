<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $order_id
 * @property string $action
 * @property string|null $from_state
 * @property string|null $to_state
 * @property string $actor
 * @property array|null $meta
 */
class OrderAudit extends Model
{
    protected $table = 'order_audit';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    /** @param array<string, mixed> $meta */
    public static function record(
        string $orderId,
        string $action,
        string $actor,
        ?string $from = null,
        ?string $to = null,
        array $meta = [],
    ): void {
        static::create([
            'order_id' => $orderId,
            'action' => $action,
            'actor' => $actor,
            'from_state' => $from,
            'to_state' => $to,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }
}
