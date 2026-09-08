<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Payments\ApplyPaymentEvent;
use App\Domain\Promo\PromoService;
use App\Domain\Realtime\EventBus;
use App\Domain\Stock\SoldOut;
use App\Domain\Stock\StockService;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateOrder
{
    public function __construct(
        private readonly PromoService $promo,
        private readonly ApplyPaymentEvent $events,
        private readonly StockService $stock,
        private readonly EventBus $bus,
    ) {}

    /**
     * Создаёт заказ идемпотентно по ключу от клиента и сразу же захватывает
     * под него единицу склада — заказ без брони никому не нужен.
     *
     * Двойной клик по «Купить» присылает два запроса с одним
     * Idempotency-Key: вставка второго уходит в конфликт по UNIQUE, и мы
     * возвращаем уже существующий заказ вместо создания второго (и вместо
     * повторного захвата единицы — она уже захвачена первым запросом).
     *
     * Порядок внутри транзакции важен (см. 6.2 спеки): вставка заказа →
     * захват единицы → резерв слота промокода → публикация события →
     * аудит. Нет единицы — вся транзакция откатывается: заказ не создаётся
     * и слот промокода не тратится, иначе отказ по складу тихо съедал бы
     * чужой лимит использований.
     *
     * $offerId имеет приоритет над $sku, если задан явно. $sku без offerId —
     * путь совместимости с кнопкой пополнения Steam первого этапа: сервер
     * сам выбирает лучшее активное предложение со свободной единицей.
     *
     * @return array{order: Order, created: bool}
     */
    public function __invoke(
        ?string $sku,
        ?string $promoCode,
        string $idempotencyKey,
        ?string $forcedId = null,
        ?int $offerId = null,
    ): array {
        $offer = $this->resolveOffer($sku, $offerId);

        $result = DB::transaction(function () use ($offer, $offerId, $promoCode, $idempotencyKey, $forcedId): array {
            $id = $forcedId ?? 'ord_'.strtolower((string) Str::ulid());

            $inserted = DB::affectingStatement(
                'INSERT INTO orders (id, sku, offer_id, amount_minor, discount_minor, total_minor, currency,
                                     status, idempotency_key, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, now(), now())
                 ON CONFLICT DO NOTHING',
                [
                    $id,
                    $offer->product_sku,
                    $offer->id,
                    $offer->price_minor,
                    $offer->price_minor,
                    $offer->currency,
                    OrderStatus::Created->value,
                    $idempotencyKey,
                ],
            );

            if ($inserted === 0) {
                // Конфликт возможен по ключу идемпотентности и по id: второй
                // случай — только у служебной ручки со заданным id, которой
                // проверяется «вебхук раньше заказа». Оба исхода означают одно:
                // заказ уже есть, создавать второй нельзя.
                $existing = Order::query()->where('idempotency_key', $idempotencyKey)->first()
                    ?? ($forcedId === null ? null : Order::query()->find($forcedId));

                if ($existing === null) {
                    throw new OrderConflict($idempotencyKey);
                }

                // sku сравнивается всегда — это часть любого запроса, явного
                // или через sku напрямую. offer_id сравнивается, только если
                // его явно назвал КЛИЕНТ: если он присылал просто sku, сервер
                // сам мог выбрать другое предложение прямо сейчас (например,
                // это же первое обращение только что забрало последнюю
                // единицу прежнего выбора) — сравнивать с ним нечестно,
                // повтор одного и того же запроса не должен превращаться в
                // конфликт из-за движения остатков между вызовами.
                if ($existing->sku !== $offer->product_sku
                    || ($offerId !== null && (int) $existing->offer_id !== $offerId)
                    || ($existing->promo_code ?? '') !== ($promoCode ?? '')) {
                    throw new OrderConflict($idempotencyKey);
                }

                return ['order' => $existing, 'created' => false];
            }

            $order = Order::query()->findOrFail($id);

            $unitId = $this->stock->reserve($offer->id, $order->id, (int) config('store.reservation_ttl'));

            if ($unitId === null) {
                // SoldOut прерывает замыкание транзакции — Laravel откатит
                // всё, что сделано выше, включая только что вставленную
                // строку заказа: платить за товар, которого не досталось,
                // некому (требование 2.3 ТЗ).
                throw new SoldOut(
                    $offer->product_sku,
                    $offer->id,
                    $this->stock->alternativeFor($offer->product_sku, $offer->id),
                );
            }

            if ($promoCode !== null && $promoCode !== '') {
                $discount = $this->promo->reserve($promoCode, $order->amount_minor, $order->id, $order->currency);

                $order->update([
                    'promo_code' => $promoCode,
                    'discount_minor' => $discount,
                    'total_minor' => $order->amount_minor - $discount,
                ]);
            }

            // Остаток предложения изменился захватом — витрина и открытые
            // карточки узнают об этом сразу, не дожидаясь опроса (1.2 ТЗ).
            $this->bus->publishOffer($offer->id);

            OrderAudit::record(
                $order->id,
                'order_created',
                'api',
                null,
                OrderStatus::Created->value,
                [
                    'sku' => $order->sku,
                    'offer_id' => $order->offer_id,
                    'total_minor' => $order->total_minor,
                    'promo_code' => $order->promo_code,
                ],
            );

            return ['order' => $order, 'created' => true];
        });

        // Вебхук мог прийти раньше создания заказа. Такие события припаркованы
        // и применяются здесь — оплата не теряется.
        if ($result['created']) {
            $this->events->applyParked($result['order']->id);
            $result['order']->refresh();
        }

        return $result;
    }

    /**
     * offer_id — источник истины, если клиент его назвал. Иначе по sku
     * выбирается лучшее активное предложение со свободной единицей.
     *
     * Если и такого нет (позиция раскуплена целиком, у всех предложений
     * пусто) — это тоже sold_out, просто без конкретного «раскупленного»
     * предложения и без альтернативы: offerId=0 сюда никогда не попадает в
     * ответ клиенту (контроллер его не показывает), это исключительно
     * внутренний признак «предложения не было вовсе».
     */
    private function resolveOffer(?string $sku, ?int $offerId): Offer
    {
        if ($offerId !== null) {
            return Offer::query()->findOrFail($offerId);
        }

        $offer = $this->stock->bestOfferFor((string) $sku);

        if ($offer === null) {
            throw new SoldOut((string) $sku, 0, null);
        }

        return $offer;
    }
}
