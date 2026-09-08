<?php

declare(strict_types=1);

namespace App\Domain\Promo;

use App\Models\Promocode;
use App\Models\PromoRedemption;
use Illuminate\Support\Facades\DB;

/**
 * Промокоды с лимитом использований.
 *
 * Скидку считает только сервер: клиент присылает код, но никогда не цену
 * и не размер скидки.
 */
final class PromoService
{
    /**
     * Занимает одно использование кода и возвращает скидку в копейках.
     *
     * Лимит соблюдается под любым параллелизмом, потому что решение
     * принимает СУБД: атомарный UPDATE с условием used_count < max_uses
     * затрагивает либо одну строку, либо ноль. Никаких «прочитать, сравнить,
     * записать» — именно там и живёт классическая гонка.
     *
     * Код проверяется и скидка считается через discountFor() ДО захвата
     * слота: неверный код или несовпадение валюты не должны прожигать
     * попытку атомарного захвата счётчика использований — иначе отказ по
     * валидации портил бы лимит промокода запросом, который к нему не имеет
     * отношения.
     *
     * @throws PromoUnavailable
     */
    public function reserve(string $code, int $amountMinor, string $orderId, string $currency): int
    {
        $discount = $this->discountFor($code, $amountMinor, $currency);

        $claimed = DB::affectingStatement(
            'UPDATE promocodes
                SET used_count = used_count + 1, updated_at = now()
              WHERE code = ? AND used_count < max_uses',
            [$code],
        );

        if ($claimed === 0) {
            throw new PromoUnavailable($code, 'limit_reached');
        }

        PromoRedemption::create([
            'code' => $code,
            'order_id' => $orderId,
            'discount_minor' => $discount,
        ]);

        return $discount;
    }

    /**
     * Чистый расчёт скидки по коду и сумме — без резервирования слота.
     *
     * Нужен RepriceOrder (см. 6.6 спеки): цена предложения изменилась после
     * брони, скидку требуется пересчитать на новую сумму, а слот лимита уже
     * занят при создании заказа и второй раз занят быть не должен — иначе
     * подтверждение новой цены сжигало бы использование промокода второй
     * раз за одно и то же место в очереди. reserve() выше использует этот
     * же метод для той же формулы, поэтому она не продублирована.
     *
     * value трактуется по типу кода: для percent это проценты,
     * для amount — копейки (справочник ТЗ задаёт рубли, сид переводит).
     *
     * @throws PromoUnavailable код не найден или не подходит по валюте.
     */
    public function discountFor(string $code, int $amountMinor, string $currency): int
    {
        $promo = Promocode::query()->find($code);

        if ($promo === null) {
            throw new PromoUnavailable($code, 'not_found');
        }

        if ($promo->type === 'amount' && $promo->currency !== null && $promo->currency !== $currency) {
            throw new PromoUnavailable($code, 'currency_mismatch');
        }

        $discount = match ($promo->type) {
            'percent' => intdiv($amountMinor * $promo->value, 100),
            'amount' => $promo->value,
            default => 0,
        };

        return max(0, min($discount, $amountMinor));
    }
}
