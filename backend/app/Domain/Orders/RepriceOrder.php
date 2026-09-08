<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Promo\PromoService;
use App\Domain\Realtime\EventBus;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderAudit;
use Illuminate\Support\Facades\DB;

/**
 * Принятие изменившейся цены предложения ДО оплаты (требование 1.3 ТЗ,
 * 6.6 спеки).
 *
 * Серверный гард в оплате (задача 6, PaymentSimulatorController) только
 * ОТКАЗЫВАЕТ платить по устаревшей цене — сам по себе он не даёт покупателю
 * способа продолжить. Эта команда — тот способ: клиент подтверждает новую
 * цену явно, заказ пересчитывается, и оплата после этого проходит гард.
 */
final class RepriceOrder
{
    public function __construct(
        private readonly PromoService $promo,
        private readonly EventBus $bus,
    ) {}

    /**
     * $expected — цена, которую клиент видел (обычно из последнего
     * offer.updated в топике catalog) и явно подтверждает. Совпадение с
     * ФАКТИЧЕСКОЙ ценой предложения обязательно: между тем, как клиент
     * увидел плашку, и этим запросом цена могла измениться ещё раз, и
     * подтверждение неактуального числа не должно тихо списать другую сумму.
     *
     * @throws RepriceRefused заказ не в статусе, допускающем reprice
     *                        (`order_not_repriceable`), либо ожидание клиента устарело
     *                        (`price_changed`, несёт актуальную цену).
     */
    public function __invoke(Order $order, int $expected): Order
    {
        return DB::transaction(function () use ($order, $expected): Order {
            // FOR UPDATE сериализует reprice с оплатой и с параллельным
            // повторным reprice того же заказа: без блокировки строки два
            // одновременных запроса могли бы оба пройти проверки ниже и
            // оба записать аудит по одному и тому же изменению цены.
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== OrderStatus::Created) {
                throw new RepriceRefused('order_not_repriceable');
            }

            $current = (int) Offer::query()->whereKey($locked->offer_id)->value('price_minor');

            if ($current !== $expected) {
                // Клиент подтверждает не ту цену, что видит сервер прямо
                // сейчас: цена предложения успела измениться ЕЩЁ РАЗ между
                // тем, как клиент её увидел, и этим запросом.
                throw new RepriceRefused('price_changed', $current);
            }

            // Цена предложения уже совпадает с зафиксированной в заказе —
            // подтверждать нечего. Двойной клик по «Оплатить по новой цене»
            // или повтор того же запроса после обрыва связи обязаны молча
            // вернуть заказ как есть, а не насчитать аудит дважды.
            if ($current === $locked->amount_minor) {
                return $locked;
            }

            $previousAmount = $locked->amount_minor;

            // Промокод пересчитывается чистым расчётом, БЕЗ второго резерва
            // слота: слот уже занят при создании заказа (см. CreateOrder) и
            // принадлежит заказу до его конца жизни. Повторный reserve() на
            // новую сумму сжёг бы второе использование из лимита промокода
            // за одно и то же место в очереди — вместо этого discountFor()
            // просто пересчитывает сумму скидки по уже занятому коду.
            $discount = $locked->promo_code === null
                ? 0
                : $this->promo->discountFor($locked->promo_code, $current, $locked->currency);

            $locked->update([
                'amount_minor' => $current,
                'discount_minor' => $discount,
                // total_minor защищён CHECK-ограничением базы
                // (orders_money_sane: total_minor = amount_minor -
                // discount_minor) — все три поля обязаны меняться в одном
                // UPDATE, иначе вставка временного промежуточного состояния
                // уронила бы транзакцию.
                'total_minor' => $current - $discount,
            ]);

            OrderAudit::record(
                $locked->id,
                'price_accepted',
                'api',
                // Статус заказа не меняется этим действием (остаётся
                // created) — from/to здесь про статус, а не про деньги,
                // как и во всех остальных вызовах OrderAudit::record по
                // проекту. Старая и новая цена — предметное содержание
                // этого действия, а не его состояние, поэтому они в meta.
                $locked->status->value,
                null,
                [
                    'previous_amount_minor' => $previousAmount,
                    'amount_minor' => $current,
                    'discount_minor' => $discount,
                    'total_minor' => $locked->total_minor,
                ],
            );

            // Только топик заказа: предложение этим действием не меняется
            // (его цену уже изменил кто-то другой раньше — витрина узнала
            // об этом своим собственным offer.updated), меняется лишь то,
            // как ЭТОТ заказ на неё реагирует.
            $this->bus->publishOrder($locked->id);

            return $locked;
        });
    }
}
