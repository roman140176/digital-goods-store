<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Delivery\DeliveryState;
use App\Domain\Orders\OrderStatus;
use App\Domain\Promo\PromoService;
use App\Domain\Realtime\EventBus;
use App\Domain\Stock\SaleResult;
use App\Domain\Stock\StockService;
use App\Jobs\DeliverOrder;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderAudit;
use App\Models\PaymentEvent;
use Illuminate\Support\Facades\DB;

/**
 * Применение событий платёжной системы.
 *
 * Вебхук может прийти несколько раз (at-least-once) и не по порядку — это
 * прямо оговорено контрактом. Отсюда три правила:
 *
 *  1. Журнал событий с event_id в первичном ключе — единственная точка
 *     идемпотентности. Повторная доставка не проходит вставку и вообще не
 *     доходит до бизнес-логики.
 *  2. Решение по заказу принимается под FOR UPDATE, поэтому из состояния
 *     created выйти может ровно одно из параллельных событий.
 *  3. Порядок определяется меткой времени платёжной системы, а не временем
 *     доставки вебхука.
 */
final class ApplyPaymentEvent
{
    /**
     * Через сколько секунд незавершённая обработка события считается
     * брошенной. Меньше этого срока запись считается взятой в работу
     * другим процессом.
     */
    private const STUCK_AFTER_SECONDS = 5;

    public function __construct(
        private readonly PromoService $promo,
        private readonly StockService $stock,
        private readonly EventBus $bus,
    ) {}

    public function __invoke(WebhookPayload $payload): EventOutcome
    {
        $inserted = DB::affectingStatement(
            'INSERT INTO payment_events (event_id, order_id, status, amount_minor, currency,
                                         provider_created_at, payload, received_at)
             VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, now())
             ON CONFLICT (event_id) DO NOTHING',
            [
                $payload->eventId,
                $payload->orderId,
                $payload->status,
                $payload->amountMinor,
                $payload->currency,
                $payload->createdAt?->toIso8601String(),
                json_encode($payload->raw, JSON_UNESCAPED_UNICODE),
            ],
        );

        // Ноль вставленных строк — этот event_id уже приходил.
        if ($inserted === 0) {
            return $this->handleRepeat($payload->eventId);
        }

        return $this->applyStored($payload->eventId);
    }

    /**
     * Повторная доставка того же event_id.
     *
     * Обычный случай — дубль: состояние заказа не читаем и не меняем.
     * Но если предыдущая попытка записала событие и упала до того, как
     * довела обработку до конца, отвечать «дубль» нельзя — оплата
     * потерялась бы навсегда. Такую запись доводим до конца.
     *
     * Свежая незавершённая запись не трогается: её прямо сейчас
     * обрабатывает другой процесс, и лишние блокировки не нужны.
     */
    private function handleRepeat(string $eventId): EventOutcome
    {
        $stored = PaymentEvent::query()->find($eventId);

        if ($stored !== null
            && $stored->processed_at === null
            && $stored->received_at !== null
            && $stored->received_at->addSeconds(self::STUCK_AFTER_SECONDS)->isPast()) {
            return $this->applyStored($eventId);
        }

        return EventOutcome::Duplicate;
    }

    /** Досылает события, чья обработка была прервана сбоем. */
    public function applyUnprocessed(int $olderThanSeconds, int $limit = 100): int
    {
        $eventIds = PaymentEvent::query()
            ->whereNull('processed_at')
            ->where('received_at', '<=', now()->subSeconds($olderThanSeconds))
            // Припаркованное событие ждёт своего заказа. Пока заказа нет,
            // трогать его незачем: иначе вебхуки на несуществующие заказы
            // занимали бы всю выборку и вытесняли настоящие.
            ->where(function ($query): void {
                $query->whereNull('outcome')
                    ->orWhere('outcome', '!=', EventOutcome::ParkedNoOrder->value)
                    ->orWhereExists(function ($sub): void {
                        $sub->selectRaw('1')
                            ->from('orders')
                            ->whereColumn('orders.id', 'payment_events.order_id');
                    });
            })
            ->orderBy('received_at')
            ->limit($limit)
            ->pluck('event_id');

        foreach ($eventIds as $eventId) {
            $this->applyStored((string) $eventId);
        }

        return $eventIds->count();
    }

    /**
     * Применяет события, пришедшие раньше создания заказа.
     * Вызывается сразу после того, как заказ появился.
     */
    public function applyParked(string $orderId): void
    {
        $eventIds = PaymentEvent::query()
            ->where('order_id', $orderId)
            ->where('outcome', EventOutcome::ParkedNoOrder->value)
            ->orderBy('provider_created_at')
            ->orderBy('received_at')
            ->pluck('event_id');

        foreach ($eventIds as $eventId) {
            $this->applyStored((string) $eventId);
        }
    }

    private function applyStored(string $eventId): EventOutcome
    {
        return DB::transaction(function () use ($eventId): EventOutcome {
            /** @var PaymentEvent $event */
            $event = PaymentEvent::query()->findOrFail($eventId);

            $order = Order::query()->lockForUpdate()->find($event->order_id);

            // Заказа ещё нет: паркуем событие и отвечаем 200. Возвращать 5xx
            // и заставлять платёжку ретраить незачем — восстановлением
            // владеет магазин, а не платёжная система.
            if ($order === null) {
                return $this->finish($event, EventOutcome::ParkedNoOrder);
            }

            // Выданный код не отзывается ничем.
            if ($order->status->isImmutable()) {
                return $this->finish($event, EventOutcome::IgnoredTerminal);
            }

            // Порядок определяет метка платёжной системы. Метки в контракте
            // с секундной точностью, поэтому ничья — обычное дело: отказ и
            // подтверждение одной секунды. На ничьей побеждает оплата, иначе
            // деньги теряются, а воскрешение заказа безопасно (см. applyPaid).
            if ($event->provider_created_at !== null && $order->last_event_at !== null) {
                $older = $event->provider_created_at < $order->last_event_at;
                $tie = $event->provider_created_at == $order->last_event_at;

                if ($older || ($tie && $event->status !== 'paid')) {
                    return $this->finish($event, EventOutcome::Stale);
                }
            }

            return match ($event->status) {
                'paid' => $this->applyPaid($event, $order),
                'failed' => $this->applyFailed($event, $order),
                default => $this->finish($event, EventOutcome::Ignored),
            };
        });
    }

    private function applyPaid(PaymentEvent $event, Order $order): EventOutcome
    {
        if ($event->amount_minor !== null && $event->amount_minor !== $order->total_minor) {
            return $this->finish($event, EventOutcome::AmountMismatch);
        }

        // Валюта события обязана совпадать с валютой заказа: без этой проверки
        // «оплата» в другой валюте на то же число прошла бы как настоящая.
        if ($event->currency !== null && $event->currency !== $order->currency) {
            return $this->finish($event, EventOutcome::CurrencyMismatch);
        }

        // Оплата уже учтена: заказ в выдаче или ждёт восстановления. Из
        // payment_failed и reservation_expired выйти можно: платёж мог
        // подтвердиться позже, и терять его нельзя — покупатель не отвечает
        // за то, что бронь формально истекла раньше, чем дошла оплата
        // (см. 6.4 спеки).
        if (! $order->status->acceptsPayment()) {
            return $this->finish($event, EventOutcome::Ignored);
        }

        $from = $order->status;

        // Продажа единицы — часть применения оплаты, а не шаг после него:
        // единица, которую заказ ещё держит, продаётся вне зависимости от
        // истёкшего дедлайна; если бронь успели отдать другому — берётся
        // другая единица того же предложения; если единиц не осталось
        // вовсе, заказ не должен уйти в paid без товара (требование 2.3 ТЗ).
        $sale = $this->stock->sellForOrder($order->id, (int) $order->offer_id);

        if ($sale === SaleResult::NoStock) {
            return $this->applyPaidWithoutStock($event, $order, $from);
        }

        $order->update([
            'status' => OrderStatus::Paid,
            'paid_event_id' => $event->event_id,
            'last_event_at' => $event->provider_created_at ?? now(),
        ]);

        $deliveryId = $this->ensureDelivery($order);

        OrderAudit::record(
            $order->id,
            'payment_paid',
            'webhook',
            $from->value,
            OrderStatus::Paid->value,
            ['event_id' => $event->event_id, 'delivery_id' => $deliveryId, 'sale' => $sale->name],
        );

        // Задача помечена afterCommit: воркер не должен увидеть заказ раньше,
        // чем транзакция зафиксируется.
        DeliverOrder::dispatch($deliveryId);

        // Заказ и его предложение публикуются в конце: остаток предложения
        // мог измениться перезахватом (SoldReclaimed), а страница заказа
        // обязана узнать о поздней оплате даже после того, как опрос по ней
        // остановился (ReservationExpired финален для опроса, но не для SSE
        // топика заказа, см. 3.3 спеки). Лишняя публикация при SoldHeld
        // безвредна — payload несёт полное состояние (см. 4.1 спеки).
        $this->bus->publishOrder($order->id);
        $this->bus->publishOffer((int) $order->offer_id);

        return $this->finish($event, EventOutcome::Applied);
    }

    /**
     * Деньги пришли, а продать нечего: заказ не может остаться без следа
     * оплаты, поэтому он помечается к возврату вместо тихой потери платежа
     * (см. 6.4 спеки, требование 2.3 ТЗ). Задача выдачи намеренно не
     * создаётся — выдавать нечего.
     *
     * Автоматического пути назад в выдачу здесь нет и в этом этапе не
     * планируется (допущение 12.4 спеки: возврата денег без реального
     * эквайринга не бывает). finish() ниже выставит этому событию
     * processed_at, а applyUnprocessed() выбирает только
     * whereNull('processed_at') — реконсилятор его уже не увидит. Заказ
     * остаётся в out_of_stock с refund_required=true до ручного действия:
     * он виден в /admin/orders тем же списком, что и заказы с ошибкой
     * выдачи, и повторную выдачу после пополнения склада или возврат делает
     * администратор.
     */
    private function applyPaidWithoutStock(PaymentEvent $event, Order $order, OrderStatus $from): EventOutcome
    {
        $order->update([
            'status' => OrderStatus::OutOfStock,
            'refund_required' => true,
            'paid_event_id' => $event->event_id,
            'last_event_at' => $event->provider_created_at ?? now(),
        ]);

        OrderAudit::record(
            $order->id,
            'payment_needs_refund',
            'webhook',
            $from->value,
            OrderStatus::OutOfStock->value,
            ['event_id' => $event->event_id, 'reason' => 'reservation_lost'],
        );

        // Только топик заказа: единиц предложения захват не тронул (reserve()
        // внутри sellForOrder ничего не нашёл), поэтому остаток витрины не
        // изменился и publishOffer здесь ничего не сообщил бы нового.
        $this->bus->publishOrder($order->id);

        return $this->finish($event, EventOutcome::NeedsRefund);
    }

    private function applyFailed(PaymentEvent $event, Order $order): EventOutcome
    {
        // Возврат уже подтверждённой оплаты в ТЗ не рассматривается:
        // реагируем только на первый исход платежа.
        if ($order->status !== OrderStatus::Created) {
            return $this->finish($event, EventOutcome::Ignored);
        }

        $order->update([
            'status' => OrderStatus::PaymentFailed,
            'last_event_at' => $event->provider_created_at ?? now(),
        ]);

        // Использование промокода в лимит НЕ возвращается. Платёж может
        // подтвердиться позже (applyPaid допускает переход из payment_failed),
        // и заказ со скидкой оживёт. Если слот к тому моменту заняли другие
        // покупатели, код оказался бы применён больше max_uses раз — прямое
        // нарушение критерия приёмки. Слот принадлежит заказу, а не попытке
        // оплаты: повторная оплата того же заказа сохраняет скидку.

        OrderAudit::record(
            $order->id,
            'payment_failed',
            'webhook',
            OrderStatus::Created->value,
            OrderStatus::PaymentFailed->value,
            ['event_id' => $event->event_id],
        );

        return $this->finish($event, EventOutcome::Applied);
    }

    /**
     * Ровно одна строка выдачи на заказ. Сколько бы параллельных событий
     * ни пришло, вторую вставку отсекает UNIQUE(order_id).
     */
    private function ensureDelivery(Order $order): int
    {
        DB::affectingStatement(
            'INSERT INTO deliveries (order_id, request_id, state, attempts, created_at, updated_at)
             VALUES (?, ?, ?, 0, now(), now())
             ON CONFLICT (order_id) DO NOTHING',
            [$order->id, Delivery::requestIdFor($order->id), DeliveryState::Pending->value],
        );

        return (int) Delivery::query()->where('order_id', $order->id)->value('id');
    }

    private function finish(PaymentEvent $event, EventOutcome $outcome): EventOutcome
    {
        // Парковка — не завершение: заказа ещё нет, событие ждёт его. Если
        // пометить его обработанным, а создание заказа не увидит парковку
        // (она закоммитилась позже его выборки), оплату не применит уже никто.
        // Поэтому processed_at остаётся пустым, и событие подберёт планировщик.
        $processedAt = $outcome === EventOutcome::ParkedNoOrder ? null : now();

        $event->forceFill([
            'outcome' => $outcome->value,
            'processed_at' => $processedAt,
        ])->save();

        return $outcome;
    }
}
