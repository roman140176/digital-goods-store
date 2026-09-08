<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Журнал реалтайм-событий витрины.
 *
 * Строка несёт полное состояние объекта на момент публикации, а не дельту
 * изменений: повторная доставка события безвредна (перезаписывает состояние
 * тем же значением), а порядок применения на стороне подписчика решается по
 * возрастанию id, а не порядком получения события (см. 4.1, 4.3 спеки).
 * Строки неизменяемы — updated_at таблице поэтому не нужен.
 *
 * @property int $id
 * @property string $topic
 * @property string $type
 * @property array $payload
 * @property \Carbon\CarbonInterface $created_at
 */
class StreamEvent extends Model
{
    protected $table = 'stream_events';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
