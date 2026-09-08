<?php

declare(strict_types=1);

namespace App\Domain\Realtime;

use Illuminate\Support\Facades\DB;

/**
 * Снимок текущего состояния предложения для события offer.updated.
 *
 * Событие несёт полное состояние объекта, а не дельту (см. 4.1 спеки):
 * повторная доставка идемпотентна, а порядок применения на клиенте решается
 * по возрастанию id, а не порядком получения. Поэтому здесь собраны все поля,
 * которые карточка предложения показывает на витрине, — не только те, что
 * изменились.
 */
final readonly class OfferState
{
    private function __construct(
        public int $offerId,
        public string $sku,
        public string $name,
        public int $priceMinor,
        public string $currency,
        public int $available,
        public string $status,
        public int $sellerId,
        public string $sellerName,
    ) {}

    /**
     * Один запрос вместо JOIN + GROUP BY: остаток — коррелированный
     * подзапрос по составному условию "available ИЛИ просроченная бронь".
     *
     * Условие ровно то же самое, каким задача 4 будет считать доступность
     * при захвате единицы (см. 6.1 спеки): единица с истёкшим reserved_until
     * физически свободна для захвата прямо сейчас, просто планировщик ещё не
     * успел проставить ей state=available. Если бы этот снимок считал иначе,
     * витрина показывала бы товар недоступным до пробуждения планировщика —
     * ровно то расхождение, которое требование 1.2 запрещает.
     */
    public static function forOffer(int $offerId): ?self
    {
        $row = DB::selectOne(
            "SELECT o.id, o.product_sku, p.name, o.price_minor, o.currency, o.status,
                    s.id AS seller_id, s.name AS seller_name,
                    (SELECT count(*) FROM stock_units u
                      WHERE u.offer_id = o.id
                        AND (u.state = 'available'
                             OR (u.state = 'reserved' AND u.reserved_until <= now()))) AS available
               FROM offers o
               JOIN products p ON p.sku = o.product_sku
               JOIN sellers  s ON s.id = o.seller_id
              WHERE o.id = ?",
            [$offerId],
        );

        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Та же выборка, что и forOffer(), но одним запросом на список id —
     * нужна там, где предложений несколько (например,
     * CatalogController::offers()): цикл по forOffer() дал бы по одному
     * запросу на предложение (N+1), а здесь их ровно два независимо от
     * длины списка (этот плюс запрос на сами id, если он вообще нужен
     * вызывающему коду).
     *
     * `o.id = ANY(?::bigint[])` вместо стольких же `?`, сколько элементов в
     * списке: список предложений одной позиции короткий и неизвестной длины
     * заранее, а ANY с одним биндингом-массивом не требует пересобирать
     * текст SQL под конкретное количество id.
     *
     * Порядок результата — снова (price_minor, id): ANY() сам по себе
     * порядок не гарантирует, а карточке предложений позиции важно
     * показывать их от дешёвого к дорогому, как и everywhere else в
     * каталоге.
     *
     * @param  list<int>  $offerIds
     * @return list<self>
     */
    public static function forOffers(array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }

        $rows = DB::select(
            "SELECT o.id, o.product_sku, p.name, o.price_minor, o.currency, o.status,
                    s.id AS seller_id, s.name AS seller_name,
                    (SELECT count(*) FROM stock_units u
                      WHERE u.offer_id = o.id
                        AND (u.state = 'available'
                             OR (u.state = 'reserved' AND u.reserved_until <= now()))) AS available
               FROM offers o
               JOIN products p ON p.sku = o.product_sku
               JOIN sellers  s ON s.id = o.seller_id
              WHERE o.id = ANY(?::bigint[])
              ORDER BY o.price_minor, o.id",
            ['{'.implode(',', $offerIds).'}'],
        );

        return array_map(self::fromRow(...), $rows);
    }

    private static function fromRow(object $row): self
    {
        return new self(
            offerId: (int) $row->id,
            sku: $row->product_sku,
            name: $row->name,
            priceMinor: (int) $row->price_minor,
            currency: $row->currency,
            available: (int) $row->available,
            status: $row->status,
            sellerId: (int) $row->seller_id,
            sellerName: $row->seller_name,
        );
    }

    /**
     * @return array{offer_id: int, sku: string, name: string, price_minor: int,
     *               currency: string, available: int, status: string,
     *               seller: array{id: int, name: string}}
     */
    public function toArray(): array
    {
        return [
            'offer_id' => $this->offerId,
            'sku' => $this->sku,
            'name' => $this->name,
            'price_minor' => $this->priceMinor,
            'currency' => $this->currency,
            'available' => $this->available,
            'status' => $this->status,
            'seller' => [
                'id' => $this->sellerId,
                'name' => $this->sellerName,
            ],
        ];
    }
}
