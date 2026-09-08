<?php

declare(strict_types=1);

namespace App\Domain\Stock;

use App\Domain\Realtime\OfferState;
use App\Models\Offer;
use App\Models\StockUnit;
use Illuminate\Support\Facades\DB;

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
     * Продаёт единицу под оплаченный заказ — три ветки 6.4 спеки, ни одна
     * не должна оставить оплаченный заказ без товара (2.3 ТЗ).
     *
     * 1) Единица физически всё ещё за заказом (state=reserved) — продаём её
     *    независимо от того, истекла ли бронь формально: кто первый в базе,
     *    тот и прав, покупатель не отвечает за расписание планировщика.
     * 2) Такой единицы нет, но заказ уже продал другую раньше (реентерабельный
     *    повторный вызов) — отвечаем тем же исходом, а не лезем в перезахват.
     * 3) Единицы за заказом нет вовсе — пробуем захватить другую единицу
     *    того же предложения по уже уплаченной цене (цену задним числом не
     *    меняем: reserve() не трогает offers.price_minor). Не найдётся и
     *    такой — возвращаем NoStock; автоматического пути назад у этого
     *    исхода нет (см. ApplyPaymentEvent::applyPaidWithoutStock) — заказ
     *    остаётся отмеченным к возврату до ручного действия администратора.
     *
     * Транзакционный контракт: метод сам не открывает транзакцию и не
     * блокирует заказ — он полагается на то, что вызывающий код уже держит
     * ОБА шага (проверку и продажу/перезахват) внутри одной транзакции БД
     * с заказом под FOR UPDATE, как делает ApplyPaymentEvent::applyStored.
     * Без этой внешней блокировки сбой ровно между захватом новой единицы
     * (reserve()) и её продажей оставил бы единицу в состоянии reserved, а
     * не sold, — заказ платёжеспособен, а склад считает единицу всё ещё
     * бронью, а не покупкой.
     */
    public function sellForOrder(string $orderId, int $offerId): SaleResult
    {
        $sold = DB::affectingStatement(
            "UPDATE stock_units
                SET state = 'sold', sold_at = now(), reserved_until = NULL, updated_at = now()
              WHERE reserved_order_id = ? AND state = 'reserved'",
            [$orderId],
        );

        if ($sold > 0) {
            return SaleResult::SoldHeld;
        }

        // Реентерабельность: заказ уже продал единицу раньше. Без этой
        // проверки повторное применение оплаты проваливалось бы в перезахват
        // ниже, reserve() выставил бы reserved_order_id этого же заказа на
        // ВТОРУЮ единицу — и получил бы 500 на частичном UNIQUE
        // stock_units_one_order_per_unit вместо тихой идемпотентности.
        // reserved_order_id проданной единицы не стирается (см. 3.2 спеки),
        // поэтому проверка сводится к одному запросу.
        if (StockUnit::query()->where('reserved_order_id', $orderId)
            ->where('state', 'sold')->exists()) {
            return SaleResult::SoldHeld;
        }

        // Бронь успели снять и отдать другому: пробуем взять другую единицу
        // того же предложения. TTL здесь короткий и не имеет значения для
        // покупателя — единица тут же продаётся следующим оператором, а не
        // остаётся в брони.
        if ($this->reserve($offerId, $orderId, 60) === null) {
            return SaleResult::NoStock;
        }

        DB::affectingStatement(
            "UPDATE stock_units
                SET state = 'sold', sold_at = now(), reserved_until = NULL, updated_at = now()
              WHERE reserved_order_id = ? AND state = 'reserved'",
            [$orderId],
        );

        return SaleResult::SoldReclaimed;
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
     * Снимает просроченные брони и возвращает единицы в продажу — вторая
     * линия защиты рядом с ленивым подбором в reserve() (см. класс-докблок
     * выше и ReleaseExpiredReservations): без отдельного тика планировщика
     * товар «оживал» бы только для того, кто сам попытается его купить, а
     * требование 3.2 ТЗ — «для всех» — обязано выполняться само по себе.
     *
     * FOR UPDATE OF u SKIP LOCKED — та же причина, что и в reserve(): не
     * выстраиваться в очередь за строками, которые прямо сейчас держит
     * другая транзакция (withoutOverlapping() защищает от параллельного
     * тика САМОГО планировщика, но не от прямого вызова releaseExpired()
     * из другого места, например из тестов).
     *
     * order_id пробрасывается из CTE (u.reserved_order_id), а не из
     * обновлённой строки s: к моменту RETURNING поле reserved_order_id у s
     * уже обнулено этим же UPDATE, и вернуть его оттуда физически нельзя —
     * CTE materialize'ится ДО обновления и хранит значение таким, каким оно
     * было на момент выборки.
     *
     * JOIN на orders с условием o.status = 'created' — единственное, что
     * защищает единицы оплаченных заказов: они удерживаются до выдачи, и
     * снятие с них брони означало бы оплаченный заказ без товара (2.3 ТЗ).
     *
     * @return list<array{unit_id: int, offer_id: int, order_id: string}>
     */
    public function releaseExpired(int $limit = 500): array
    {
        $rows = DB::select(<<<'SQL'
            WITH expired AS (
              SELECT u.id, u.reserved_order_id FROM stock_units u
               JOIN orders o ON o.id = u.reserved_order_id
               WHERE u.state = 'reserved' AND u.reserved_until <= now()
                 AND o.status = 'created'
               ORDER BY u.reserved_until
               FOR UPDATE OF u SKIP LOCKED
               LIMIT ?)
            UPDATE stock_units s
               SET state = 'available', reserved_order_id = NULL,
                   reserved_until = NULL, updated_at = now()
              FROM expired
             WHERE s.id = expired.id
            RETURNING s.id AS unit_id, s.offer_id, expired.reserved_order_id AS order_id
        SQL, [$limit]);

        return array_map(
            static fn (object $row): array => [
                'unit_id' => (int) $row->unit_id,
                'offer_id' => (int) $row->offer_id,
                'order_id' => (string) $row->order_id,
            ],
            $rows,
        );
    }

    /**
     * Просрочивает бронь заказа прямо сейчас — служебная ручка для сценариев
     * приёмки и демонстрации (5.4 спеки): не ждать TTL целиком ни в тестах,
     * ни на демонстрации. Само снятие брони этот метод не делает — только
     * сдвигает дедлайн в прошлое, а освобождение остаётся единственной
     * работой releaseExpired(), чтобы у инварианта была одна точка
     * выполнения, а не две слегка разные.
     *
     * true, только если у заказа была активная бронь (единица в состоянии
     * reserved): уже проданная или уже снятая бронь — не ошибка вызывающего
     * кода, но и презентовать как «что-то просрочили» нечего.
     */
    public function expireNow(string $orderId): bool
    {
        $affected = DB::affectingStatement(
            "UPDATE stock_units SET reserved_until = now() - interval '1 second', updated_at = now()
              WHERE reserved_order_id = ? AND state = 'reserved'",
            [$orderId],
        );

        return $affected > 0;
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
