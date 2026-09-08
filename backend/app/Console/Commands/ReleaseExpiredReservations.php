<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Orders\OrderStatus;
use App\Domain\Realtime\EventBus;
use App\Domain\Stock\StockService;
use App\Models\Order;
use App\Models\OrderAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Снимает просроченные брони и возвращает товар в продажу для всех.
 *
 * StockService::reserve уже подбирает истёкшую бронь лениво прямо при
 * захвате (см. 6.1 спеки) — эта команда её не дублирует, у двух путей
 * разные задачи. Ленивый путь чинит бронь только тому, кто ПРЯМО СЕЙЧАС
 * пытается купить то же самое предложение; покупатель за соседним столом,
 * который просто смотрит на витрину, узнаёт об освободившемся товаре
 * только через событие offer.updated — а публиковать это событие некому,
 * пока кто-нибудь не попытается купить. Без отдельного тика планировщика
 * «возврат в продажу для всех» (3.2 ТЗ) наступал бы только по случайному
 * стечению обстоятельств, а не гарантированно.
 */
final class ReleaseExpiredReservations extends Command
{
    protected $signature = 'reservations:release {--limit=500}';

    protected $description = 'Снимает просроченные брони и возвращает единицы в продажу для всех';

    public function handle(StockService $stock, EventBus $bus): int
    {
        $limit = (int) $this->option('limit');

        [$releasedCount, $expiredOrders] = DB::transaction(function () use ($stock, $bus, $limit): array {
            $released = $stock->releaseExpired($limit);

            if ($released === []) {
                return [0, 0];
            }

            $expiredOrders = 0;

            foreach (array_unique(array_column($released, 'order_id')) as $orderId) {
                $expiredOrders += $this->expireOrder((string) $orderId, $bus);
            }

            foreach (array_unique(array_column($released, 'offer_id')) as $offerId) {
                // Затронутые предложения — это факт про stock_units (единица
                // физически свободна), а не про то, удалось ли перевести
                // конкретный заказ статусом ниже: витрина обязана увидеть
                // освободившийся товар вне зависимости от гварда в expireOrder().
                $bus->publishOffer((int) $offerId);
            }

            return [count($released), $expiredOrders];
        });

        $this->info(sprintf(
            'Освобождено единиц: %d, заказов переведено в reservation_expired: %d',
            $releasedCount,
            $expiredOrders,
        ));

        return self::SUCCESS;
    }

    /**
     * Переводит один заказ в reservation_expired, только если он всё ещё
     * created. Гвард статуса повторяется здесь же, а не только внутри
     * releaseExpired(): та часть — отдельный оператор SQL со своим снимком
     * (releaseExpired блокирует только stock_units, не orders), и между ним
     * и этим запросом конкурентная оплата того же заказа теоретически
     * успевает закоммититься. Без повторной проверки заказ задним числом
     * переписался бы обратно в reservation_expired поверх уже принятой
     * оплаты — ровно то, от чего защищает условие status='created' (2.3,
     * 3.3 ТЗ).
     */
    private function expireOrder(string $orderId, EventBus $bus): int
    {
        $expired = Order::query()->whereKey($orderId)
            ->where('status', OrderStatus::Created->value)
            ->update(['status' => OrderStatus::ReservationExpired->value, 'updated_at' => now()]);

        if ($expired === 0) {
            return 0;
        }

        OrderAudit::record(
            $orderId,
            'reservation_expired',
            'scheduler',
            OrderStatus::Created->value,
            OrderStatus::ReservationExpired->value,
        );

        $bus->publishOrder($orderId);

        return 1;
    }
}
