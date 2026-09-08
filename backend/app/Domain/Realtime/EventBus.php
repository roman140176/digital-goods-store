<?php

declare(strict_types=1);

namespace App\Domain\Realtime;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Публикация событий реалтайм-канала: журнал stream_events + pg_notify.
 *
 * Вставка события и pg_notify обязаны выполняться в ТОЙ ЖЕ транзакции, что и
 * изменение бизнес-данных (остаток единиц, статус заказа и т. д.) — это
 * ответственность вызывающего кода (CreateOrder, ApplyPaymentEvent и другие
 * задачи 4-7 уже оборачивают свою работу в DB::transaction()). Публикация
 * сама транзакцию не открывает: она просто выполняет операторы в уже
 * действующем транзакционном контексте соединения.
 *
 * Отсюда главная гарантия канала, а не просто удобство: PostgreSQL
 * доставляет NOTIFY подписчикам только в момент COMMIT транзакции. Поэтому
 * подписчик физически не может получить уведомление о строке, которая ему
 * ещё не видна, — вставка и NOTIFY становятся видимы одновременно, атомарно
 * для внешнего наблюдателя. И наоборот: откат транзакции убирает NOTIFY
 * вместе со всем остальным, что она делала, — откатившийся захват единицы
 * (см. 6.1, 6.2 спеки) не порождает ни строки в журнале, ни уведомления.
 * Повторить то же самое отдельным способом поверх обычного INSERT нельзя —
 * это и есть согласованность снапшота и потока из 4.5 спеки.
 */
final class EventBus
{
    /**
     * Единый канал pg_notify для всех топиков. Стример (задача 9) читает
     * topic из самой строки журнала и сам раздаёт событие только тем
     * подключениям, что на этот топик подписаны.
     */
    private const CHANNEL = 'storefront';

    /**
     * Записывает событие в журнал и будит стример через pg_notify.
     *
     * В уведомлении — только id, а не payload целиком: у pg_notify жёсткий
     * лимит в 8000 байт на полезную нагрузку, а состояние объекта (задача 4
     * добавит туда ещё и данные брони) может этот лимит превысить. Стример
     * дочитывает полное состояние из журнала по id — под это и рассчитаны
     * все запросы к stream_events вида "id > курсор".
     */
    public function publish(string $topic, string $type, array $payload): int
    {
        $id = (int) DB::scalar(
            'INSERT INTO stream_events (topic, type, payload, created_at)
             VALUES (?, ?, ?::jsonb, now()) RETURNING id',
            [$topic, $type, json_encode($payload, JSON_UNESCAPED_UNICODE)],
        );

        DB::statement('SELECT pg_notify(?, ?)', [self::CHANNEL, (string) $id]);

        return $id;
    }

    /**
     * Читает текущее состояние предложения и публикует offer.updated
     * в общий топик catalog.
     *
     * null, если предложения уже нет — короткое замыкание до похода в
     * журнал: публиковать событие о несуществующем объекте бессмысленно,
     * а вызывающему коду (например, снятию просроченной брони по нескольким
     * предложениям сразу) не нужно самому разбирать этот случай.
     */
    public function publishOffer(int $offerId): ?int
    {
        $state = OfferState::forOffer($offerId);

        if ($state === null) {
            return null;
        }

        return $this->publish('catalog', 'offer.updated', $state->toArray());
    }

    /**
     * Публикует текущее состояние заказа в его собственный топик order:<id>.
     *
     * Отдельный топик на заказ, а не общий catalog: у заказа один-два
     * заинтересованных подписчика (покупатель, админка), и им не нужно
     * получать события по всем чужим предложениям витрины.
     */
    public function publishOrder(string $orderId): ?int
    {
        $order = Order::query()->find($orderId);

        if ($order === null) {
            return null;
        }

        return $this->publish('order:'.$orderId, 'order.updated', [
            'id' => $order->id,
            'status' => $order->status->value,
            'sku' => $order->sku,
            'offer_id' => $order->offer_id === null ? null : (int) $order->offer_id,
            'amount_minor' => $order->amount_minor,
            'discount_minor' => $order->discount_minor,
            'total_minor' => $order->total_minor,
            'currency' => $order->currency,
            'refund_required' => (bool) $order->refund_required,
        ]);
    }

    /**
     * coalesce(max(id), 0) — курсор для API-снапшотов (см. 4.5 спеки). Ноль,
     * а не null, для пустого журнала: клиент трактует курсор как число и
     * запрашивает хвост "id > 0", то есть всю историю, без отдельной ветки
     * на пустой журнал.
     */
    public function cursor(): int
    {
        return (int) (DB::scalar('SELECT coalesce(max(id), 0) FROM stream_events') ?? 0);
    }
}
