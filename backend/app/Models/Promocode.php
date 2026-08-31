<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $code
 * @property string $type
 * @property int $value
 * @property string|null $currency
 * @property int $max_uses
 * @property int $used_count
 */
class Promocode extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'value' => 'integer',
        'max_uses' => 'integer',
        'used_count' => 'integer',
    ];
}
