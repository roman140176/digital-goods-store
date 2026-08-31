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
     * @throws PromoUnavailable
     */
    public function reserve(string $code, int $amountMinor, string $orderId, string $currency): int
    {
        $promo = Promocode::query()->find($code);

        if ($promo === null) {
            throw new PromoUnavailable($code, 'not_found');
        }

        if ($promo->type === 'amount' && $promo->currency !== null && $promo->currency !== $currency) {
            throw new PromoUnavailable($code, 'currency_mismatch');
        }

        $claimed = DB::affectingStatement(
            'UPDATE promocodes
                SET used_count = used_count + 1, updated_at = now()
              WHERE code = ? AND used_count < max_uses',
            [$code],
        );

        if ($claimed === 0) {
            throw new PromoUnavailable($code, 'limit_reached');
        }

        $discount = $this->discountFor($promo, $amountMinor);

        PromoRedemption::create([
            'code' => $code,
            'order_id' => $orderId,
            'discount_minor' => $discount,
        ]);

        return $discount;
    }

    /**
     * Возвращает использование в лимит, когда оплата не прошла.
     * Строку списания не удаляем: в аудите должно остаться, что код применялся.
     */
    public function release(string $code, string $orderId): void
    {
        DB::transaction(function () use ($code, $orderId): void {
            $released = DB::affectingStatement(
                'UPDATE promo_redemptions
                    SET released_at = now(), updated_at = now()
                  WHERE order_id = ? AND code = ? AND released_at IS NULL',
                [$orderId, $code],
            );

            if ($released > 0) {
                DB::affectingStatement(
                    'UPDATE promocodes
                        SET used_count = used_count - 1, updated_at = now()
                      WHERE code = ? AND used_count > 0',
                    [$code],
                );
            }
        });
    }

    /**
     * value трактуется по типу кода: для percent это проценты,
     * для amount — копейки (справочник ТЗ задаёт рубли, сид переводит).
     */
    public function discountFor(Promocode $promo, int $amountMinor): int
    {
        $discount = match ($promo->type) {
            'percent' => intdiv($amountMinor * $promo->value, 100),
            'amount' => $promo->value,
            default => 0,
        };

        return max(0, min($discount, $amountMinor));
    }
}
