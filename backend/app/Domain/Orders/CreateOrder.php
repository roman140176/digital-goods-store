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
     * Существующий заказ по ключу ищется РАНЬШЕ, чем резолвится предложение
     * (см. правку после ревью задачи 4a): резолвинг по sku зависит от того,
     * есть ли ПРЯМО СЕЙЧАС свободная единица, а у повторного вызова её может
     * не быть — она уже захвачена первым же вызовом этого клиента, и если
     * к моменту повтора остальные предложения позиции распроданы кем-то
     * другим, резолвинг решил бы, что всё раскуплено, хотя у клиента уже
     * есть действующий заказ. Резолвинг по явному offer_id от текущего
     * остатка не зависит, но найденный по ключу заказ всё равно возвращается
     * раньше — незачем резолвить то, что не понадобится.
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
        $result = DB::transaction(function () use ($sku, $offerId, $promoCode, $idempotencyKey, $forcedId): array {
            $existing = $this->findExisting($idempotencyKey, $forcedId);

            if ($existing !== null) {
                $this->assertMatchesRequest($existing, $sku, $offerId, $promoCode, $idempotencyKey);

                return ['order' => $existing, 'created' => false];
            }

            $offer = $this->resolveOffer($sku, $offerId);

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
                // Настоящая гонка: конкурентный запрос с тем же ключом успел
                // вставить строку между проверкой выше и этой вставкой.
                $existing = $this->findExisting($idempotencyKey, $forcedId);

                if ($existing === null) {
                    throw new OrderConflict($idempotencyKey);
                }

                $this->assertMatchesRequest($existing, $sku, $offerId, $promoCode, $idempotencyKey);

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
     * Заказ уже существует под этим ключом идемпотентности — второй случай
     * (по id) достижим только у служебной ручки со заданным id, которой
     * проверяется «вебхук раньше заказа».
     */
    private function findExisting(string $idempotencyKey, ?string $forcedId): ?Order
    {
        return Order::query()->where('idempotency_key', $idempotencyKey)->first()
            ?? ($forcedId === null ? null : Order::query()->find($forcedId));
    }

    /**
     * Тот же ключ с другим телом — это не повтор, а ошибка клиента: молча
     * вернуть заказ на другой товар или предложение нельзя.
     *
     * offer_id сравнивается, только если его явно назвал КЛИЕНТ в этом
     * вызове: offer_id — устойчивый идентификатор ровно того предложения,
     * которое он просил, и это сравнение не зависит от текущего остатка.
     * Путь через голый sku сравнивается по sku напрямую (а не по
     * пере-резолвленному предложению — см. правку после ревью задачи 4a):
     * когда клиент не называет offer_id, сервер сам выбирает предложение
     * заново при каждом вызове, и остаток между вызовами мог сдвинуться —
     * сравнивать с тем, что сервер выбрал бы ПРЯМО СЕЙЧАС, нечестно к
     * повтору одного и того же запроса.
     */
    private function assertMatchesRequest(
        Order $existing,
        ?string $sku,
        ?int $offerId,
        ?string $promoCode,
        string $idempotencyKey,
    ): void {
        $offerMismatch = $offerId !== null && (int) $existing->offer_id !== $offerId;
        $skuMismatch = $offerId === null && $sku !== null && $existing->sku !== $sku;
        $promoMismatch = ($existing->promo_code ?? '') !== ($promoCode ?? '');

        if ($offerMismatch || $skuMismatch || $promoMismatch) {
            throw new OrderConflict($idempotencyKey);
        }
    }

    /**
     * offer_id — источник истины, если клиент его назвал. Иначе по sku
     * выбирается лучшее активное предложение со свободной единицей.
     *
     * Явно названное предложение, снятое с продажи (status='hidden'),
     * трактуется как sold_out с той же альтернативой, что и раскупленное:
     * status — единственный механизм, которым предложение снимается с
     * продажи, не удаляя строку, и обход этого захватом по прямому
     * offer_id (например, админ скрыл предложение, пока у покупателя уже
     * была открыта страница с его id) убил бы весь смысл поля (см. правку
     * после ревью задачи 4a).
     *
     * Если по sku активного предложения со свободной единицей нет вовсе
     * (позиция раскуплена целиком) — это тоже sold_out, просто без
     * конкретного «раскупленного» предложения и без альтернативы:
     * offerId=0 сюда никогда не попадает в ответ клиенту (контроллер его не
     * показывает), это исключительно внутренний признак «предложения не
     * было вовсе».
     */
    private function resolveOffer(?string $sku, ?int $offerId): Offer
    {
        if ($offerId !== null) {
            $offer = Offer::query()->findOrFail($offerId);

            if ($offer->status !== 'active') {
                throw new SoldOut(
                    $offer->product_sku,
                    $offer->id,
                    $this->stock->alternativeFor($offer->product_sku, $offer->id),
                );
            }

            return $offer;
        }

        $offer = $this->stock->bestOfferFor((string) $sku);

        if ($offer === null) {
            throw new SoldOut((string) $sku, 0, null);
        }

        return $offer;
    }
}
