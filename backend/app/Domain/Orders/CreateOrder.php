<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Payments\ApplyPaymentEvent;
use App\Domain\Promo\PromoService;
use App\Models\Order;
use App\Models\OrderAudit;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateOrder
{
    public function __construct(
        private readonly PromoService $promo,
        private readonly ApplyPaymentEvent $events,
    ) {}

    /**
     * Создаёт заказ идемпотентно по ключу от клиента.
     *
     * Двойной клик по «Купить» присылает два запроса с одним
     * Idempotency-Key: вставка второго уходит в конфликт по UNIQUE, и мы
     * возвращаем уже существующий заказ вместо создания второго.
     *
     * Порядок внутри транзакции важен: использование промокода занимается
     * только после того, как заказ реально вставился. Иначе двойной клик
     * с промокодом сжёг бы два использования из лимита.
     *
     * @return array{order: Order, created: bool}
     */
    public function __invoke(
        string $sku,
        ?string $promoCode,
        string $idempotencyKey,
        ?string $forcedId = null,
    ): array {
        $product = Product::query()->findOrFail($sku);

        $result = DB::transaction(function () use ($product, $promoCode, $idempotencyKey, $forcedId): array {
            $id = $forcedId ?? 'ord_'.strtolower((string) Str::ulid());

            $inserted = DB::affectingStatement(
                'INSERT INTO orders (id, sku, amount_minor, discount_minor, total_minor, currency,
                                     status, idempotency_key, created_at, updated_at)
                 VALUES (?, ?, ?, 0, ?, ?, ?, ?, now(), now())
                 ON CONFLICT DO NOTHING',
                [
                    $id,
                    $product->sku,
                    $product->price_minor,
                    $product->price_minor,
                    $product->currency,
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

                // Тот же ключ с другим телом — это не повтор, а ошибка клиента:
                // молча вернуть заказ на другой товар нельзя.
                if ($existing->sku !== $product->sku
                    || ($existing->promo_code ?? '') !== ($promoCode ?? '')) {
                    throw new OrderConflict($idempotencyKey);
                }

                return ['order' => $existing, 'created' => false];
            }

            $order = Order::query()->findOrFail($id);

            if ($promoCode !== null && $promoCode !== '') {
                $discount = $this->promo->reserve($promoCode, $order->amount_minor, $order->id, $order->currency);

                $order->update([
                    'promo_code' => $promoCode,
                    'discount_minor' => $discount,
                    'total_minor' => $order->amount_minor - $discount,
                ]);
            }

            OrderAudit::record(
                $order->id,
                'order_created',
                'api',
                null,
                OrderStatus::Created->value,
                ['sku' => $order->sku, 'total_minor' => $order->total_minor, 'promo_code' => $order->promo_code],
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
}
