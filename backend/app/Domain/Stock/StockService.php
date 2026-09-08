<?php

declare(strict_types=1);

namespace App\Domain\Stock;

use App\Domain\Realtime\OfferState;
use App\Models\Offer;
use App\Models\StockUnit;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Захват, поиск и продажа единиц склада под конкретное предложение.
 *
 * Единица физически принадлежит заказу через одно поле reserved_order_id,
 * и частичный UNIQUE в базе (stock_units_one_order_per_unit, см. 3.2 спеки)
 * не даёт этому полю указывать на два заказа одновременно — ровно это и
 * делает захват безопасным под любым параллелизмом без блокировок в PHP.
 */
final class StockService
{
    /**
     * Захватывает одну единицу предложения под заказ и ставит дедлайн брони.
     *
     * FOR UPDATE SKIP LOCKED — параллельные покупатели берут РАЗНЫЕ строки
     * и не выстраиваются в очередь друг за другом: если строку уже держит
     * другая транзакция, она просто пропускается, а не ждётся.
     *
     * Условие в WHERE подбирает не только физически свободные единицы, но и
     * чужую просроченную бронь прямо здесь, лениво: корректность захвата не
     * зависит от того, проснулся ли планировщик ReleaseExpiredReservations
     * (см. 6.1 спеки) — иначе товар «залипал» бы до его тика.
     *
     * ORDER BY (state='available') DESC — по-настоящему свободную единицу
     * забираем раньше, чем отбираем чужую истёкшую бронь: если есть выбор,
     * нет смысла отбирать что-то у другого заказа.
     *
     * Две попытки с паузой 50 мс: SKIP LOCKED при пустом результате не
     * отличает «единиц действительно нет» от «все подходящие строки прямо
     * сейчас заблокированы конкурентами» — без повтора кратковременная
     * занятость превращалась бы в ложный отказ покупателю.
     */
    public function reserve(int $offerId, string $orderId, int $ttlSeconds): ?int
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $id = DB::scalar(<<<'SQL'
                WITH pick AS (
                  SELECT id FROM stock_units
                   WHERE offer_id = ?
                     AND (state = 'available'
                          OR (state = 'reserved' AND reserved_until <= now()))
                   ORDER BY (state = 'available') DESC, id
                   FOR UPDATE SKIP LOCKED
                   LIMIT 1)
                UPDATE stock_units s
                   SET state = 'reserved', reserved_order_id = ?,
                       reserved_until = now() + (? || ' seconds')::interval,
                       updated_at = now()
                  FROM pick WHERE s.id = pick.id
                RETURNING s.id
            SQL, [$offerId, $orderId, $ttlSeconds]);

            if ($id !== null) {
                return (int) $id;
            }

            if ($attempt === 1) {
                usleep(50_000);
            }
        }

        return null;
    }

    /**
     * Продаёт единицу, удержанную заказом на момент оплаты.
     *
     * Объявлен как часть контракта задачи 4a (см. предполётные решения:
     * CreateOrder и OrderController уже опираются на существование этого
     * метода через тип SaleResult), но сама продажа — часть жизненного цикла
     * оплаты (6.4 спеки), которая реализуется в задаче 6. Пустая заглушка,
     * которая молча возвращала бы, скажем, NoStock, замаскировала бы это
     * отсутствие поведения под настоящий результат — поэтому явный отказ.
     */
    public function sellForOrder(string $orderId, int $offerId): SaleResult
    {
        throw new LogicException('StockService::sellForOrder реализуется в задаче 6.');
    }

    /**
     * Единица, которую сейчас держит заказ, — свободная бронь или уже
     * проданная (reserved_order_id не стирается продажей, см. 3.1 спеки).
     */
    public function unitFor(string $orderId): ?StockUnit
    {
        return StockUnit::query()->where('reserved_order_id', $orderId)->first();
    }

    /**
     * Сколько единиц предложения можно захватить прямо сейчас: физически
     * свободные плюс чужая просроченная бронь — то же самое условие
     * доступности, что и в reserve() и в OfferState (см. 6.1 спеки).
     */
    public function availableCount(int $offerId): int
    {
        return (int) DB::scalar(
            "SELECT count(*) FROM stock_units
              WHERE offer_id = ?
                AND (state = 'available' OR (state = 'reserved' AND reserved_until <= now()))",
            [$offerId],
        );
    }

    /**
     * Следующее по цене активное предложение той же позиции со свободной
     * единицей, кроме уже раскупленного, — «предложение другого продавца»
     * из требования 2.2 ТЗ, которым 409 sold_out отвечает вместо тупика.
     */
    public function alternativeFor(string $sku, int $excludeOfferId): ?OfferState
    {
        $offerId = $this->firstAvailableOfferId($sku, $excludeOfferId);

        return $offerId === null ? null : OfferState::forOffer($offerId);
    }

    /**
     * Лучшее (самое дешёвое) активное предложение позиции с реально
     * свободной единицей прямо сейчас.
     *
     * Нужен для совместимости приёма sku без offer_id (кнопка пополнения
     * Steam первого этапа, см. решения задачи 4a): раз клиент не называет
     * конкретное предложение, сервер обязан выбрать то, что действительно
     * можно продать, а не просто самое дешёвое из существующих, — иначе
     * покупка упиралась бы в sold_out по случайности выбора сервера, хотя у
     * позиции в целом есть товар у другого продавца.
     */
    public function bestOfferFor(string $sku): ?Offer
    {
        // excludeOfferId = 0 здесь означает «не исключать ничего»: bigserial
        // считает с 1, поэтому предложения с id=0 в принципе не существует, и
        // отдельная ветка SQL «без исключения» не нужна.
        $offerId = $this->firstAvailableOfferId($sku, 0);

        return $offerId === null ? null : Offer::query()->find($offerId);
    }

    private function firstAvailableOfferId(string $sku, int $excludeOfferId): ?int
    {
        $offerId = DB::scalar(
            "SELECT o.id FROM offers o
              WHERE o.product_sku = ? AND o.status = 'active' AND o.id <> ?
                AND EXISTS (
                      SELECT 1 FROM stock_units u
                       WHERE u.offer_id = o.id
                         AND (u.state = 'available'
                              OR (u.state = 'reserved' AND u.reserved_until <= now())))
              ORDER BY o.price_minor, o.id
              LIMIT 1",
            [$sku, $excludeOfferId],
        );

        return $offerId === null ? null : (int) $offerId;
    }
}
