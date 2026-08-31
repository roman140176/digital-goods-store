<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Promocode;
use Illuminate\Database\Seeder;

/**
 * Промокоды из приложения к ТЗ.
 *
 * value трактуется по типу: для percent это проценты, для amount — копейки.
 * В справочнике ТЗ сумма задана в рублях, поэтому здесь она умножается на 100.
 */
final class PromocodeSeeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            ['WELCOME10', 'percent', 10, null, 100],
            ['GG500', 'amount', 500 * 100, 'RUB', 20],
            ['LIMIT3', 'percent', 25, null, 3],
            ['ONCEONLY', 'percent', 50, null, 1],
        ];

        foreach ($codes as [$code, $type, $value, $currency, $maxUses]) {
            Promocode::query()->updateOrCreate(['code' => $code], [
                'type' => $type,
                'value' => $value,
                'currency' => $currency,
                'max_uses' => $maxUses,
                // used_count намеренно не переписывается: `make up` гоняет сид
                // при каждом запуске, а обнуление счётчика тихо возвращало бы
                // исчерпанные лимиты. У новой строки он равен DEFAULT 0.
            ]);
        }
    }
}
