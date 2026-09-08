<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Orders\OrderPresenter;
use App\Domain\Realtime\EventBus;
use App\Domain\Realtime\OfferState;
use App\Models\Offer;
use App\Models\Order;
use App\Models\StockUnit;
use App\Models\StreamEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EventBusTest extends TestCase
{
    use RefreshDatabase;

    private EventBus $bus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->bus = $this->app->make(EventBus::class);
    }

    public function test_offer_event_carries_full_state_not_a_delta(): void
    {
        $offer = Offer::query()->orderBy('id')->firstOrFail();

        $id = $this->bus->publishOffer($offer->id);

        $event = StreamEvent::query()->findOrFail($id);

        $this->assertSame('catalog', $event->topic);
        $this->assertSame('offer.updated', $event->type);
        $this->assertSame($offer->id, $event->payload['offer_id']);
        $this->assertSame($offer->price_minor, $event->payload['price_minor']);
        $this->assertArrayHasKey('available', $event->payload);
        $this->assertArrayHasKey('name', $event->payload);
        $this->assertSame($offer->seller_id, $event->payload['seller']['id']);
    }

    /**
     * Граница того, что доказывает именно этот тест, а что — нет.
     *
     * Доказывает: после отмены транзакции в журнале нет новой строки.
     * Это наблюдаемо изнутри того же соединения и проверяется assertSame
     * ниже напрямую — сравнение "было/стало" не проходит вслепую (см. отчёт
     * задачи 3: диверсия с записью через отдельное физическое соединение
     * заставила именно этот assertSame покраснеть, остальные тесты файла
     * остались зелёными).
     *
     * НЕ доказывает и не может: что уведомление по каналу storefront не
     * дошло до подписчика. Тест сам выполняется внутри незакоммиченной
     * транзакции RefreshDatabase — снаружи неё нечего наблюдать даже в
     * принципе, поэтому "долетело ли NOTIFY" здесь недоказуемо никаким
     * PHPUnit-assertion. Отмена NOTIFY при откате — задокументированное
     * поведение PostgreSQL (уведомления доставляются только при COMMIT
     * транзакции, в которой они поставлены в очередь) и отдельно
     * подтверждена вживую вне PHPUnit: psql LISTEN-сессия получила
     * уведомление ровно для закоммиченной публикации и ни строки, ни
     * уведомления для откатившейся не возникло (см. раздел "Проверка
     * доставки pg_notify до слушателя" в отчёте задачи 3).
     */
    public function test_rolled_back_transaction_leaves_no_event(): void
    {
        $offer = Offer::query()->orderBy('id')->firstOrFail();
        $before = StreamEvent::query()->count();

        try {
            DB::transaction(function () use ($offer): void {
                $this->bus->publishOffer($offer->id);

                throw new \RuntimeException('откат');
            });
        } catch (\RuntimeException) {
            // ожидаемо
        }

        // Событие живёт в той же транзакции, что и данные: подписчик не должен
        // узнать об изменении, которого не было.
        $this->assertSame($before, StreamEvent::query()->count());
    }

    public function test_cursor_returns_last_event_id_and_zero_on_empty_journal(): void
    {
        StreamEvent::query()->delete();
        $this->assertSame(0, $this->bus->cursor());

        $id = $this->bus->publish('catalog', 'offer.updated', ['offer_id' => 1]);

        $this->assertSame($id, $this->bus->cursor());
    }

    public function test_order_event_goes_to_its_own_topic(): void
    {
        $id = $this->bus->publish('order:ord_x', 'order.updated', ['id' => 'ord_x']);

        $this->assertSame('order:ord_x', StreamEvent::query()->findOrFail($id)->topic);
    }

    /**
     * Единица физически ещё 'reserved' в базе — планировщик её не подобрал,
     * но reserved_until уже в прошлом. Захват единицы в задаче 4 будет
     * считать такую единицу свободной (см. 6.1 спеки), и снимок для витрины
     * обязан считать так же: иначе кнопка «Купить» ложно погасла бы у всех
     * ровно до секунды, пока ReleaseExpiredReservations не проснётся —
     * а этого 1.2 прямо запрещает.
     */
    public function test_expired_reservation_still_counts_as_available(): void
    {
        // KEY-CS2-PRIME/самое дешёвое предложение сидируется ровно с одной
        // единицей (см. CatalogSeedTest) — удобно проверить счётчик именно
        // на нём: без путаницы с остальными свободными единицами позиции.
        $hot = Offer::query()
            ->where('product_sku', 'KEY-CS2-PRIME')
            ->orderBy('price_minor')
            ->firstOrFail();

        $order = Order::query()->create([
            'id' => 'ord_evb_expired',
            'sku' => $hot->product_sku,
            'offer_id' => $hot->id,
            'amount_minor' => $hot->price_minor,
            'discount_minor' => 0,
            'total_minor' => $hot->price_minor,
            'currency' => $hot->currency,
            'status' => 'reservation_expired',
            'idempotency_key' => 'evb-expired-1',
        ]);

        StockUnit::query()->where('offer_id', $hot->id)->firstOrFail()->update([
            'state' => 'reserved',
            'reserved_order_id' => $order->id,
            'reserved_until' => now()->subMinute(),
        ]);

        $id = $this->bus->publishOffer($hot->id);

        $this->assertSame(1, StreamEvent::query()->findOrFail($id)->payload['available']);
    }

    public function test_offer_state_returns_null_for_missing_offer(): void
    {
        $missingId = ((int) Offer::query()->max('id')) + 1000;

        $this->assertNull(OfferState::forOffer($missingId));
    }

    public function test_publish_offer_returns_null_and_writes_nothing_for_missing_offer(): void
    {
        $missingId = ((int) Offer::query()->max('id')) + 1000;
        $before = StreamEvent::query()->count();

        $this->assertNull($this->bus->publishOffer($missingId));
        $this->assertSame($before, StreamEvent::query()->count());
    }

    /**
     * Задача 4a: publishOrder отдаёт ровно OrderPresenter::toArray(), а не
     * узкий отдельно собранный набор полей, — тест на форму payload заказа
     * поправлен под это синхронно с самим EventBus (см. предполётные
     * решения задачи 4a).
     *
     * stream_cursor сравнивается по отдельности, а не как часть общего
     * сравнения: он считается ДО вставки события (см. 4.5 спеки), поэтому
     * значение внутри уже опубликованного payload на единицу меньше того,
     * что вернул бы OrderPresenter, вызванный ПОСЛЕ публикации, — не баг,
     * а неизбежное следствие момента чтения курсора, и сравнивать его
     * значение вслепую здесь означало бы проверять не тот факт.
     *
     * assertEquals, а не assertSame: колонка payload — jsonb, а не json,
     * и Postgres хранит jsonb в разложенном бинарном виде, не обязанном
     * помнить порядок ключей исходного объекта (сам же проверено вживую:
     * '{"b":1,"a":2}'::jsonb превращается в {"a": 2, "b": 1}). Это свойство
     * типа данных, а не утечка нашего кода, — assertSame сравнивал бы ещё и
     * порядок ключей массива и был бы хрупким тестом на деталь реализации
     * PostgreSQL, а не на состав payload.
     */
    public function test_publish_order_carries_full_order_state_to_its_own_topic(): void
    {
        $offer = Offer::query()->orderBy('id')->firstOrFail();

        $order = Order::query()->create([
            'id' => 'ord_evb_order_1',
            'sku' => $offer->product_sku,
            'offer_id' => $offer->id,
            'amount_minor' => $offer->price_minor,
            'discount_minor' => 0,
            'total_minor' => $offer->price_minor,
            'currency' => $offer->currency,
            'status' => 'created',
            'idempotency_key' => 'evb-order-1',
        ]);

        $id = $this->bus->publishOrder($order->id);

        $event = StreamEvent::query()->findOrFail($id);

        $this->assertSame('order:'.$order->id, $event->topic);
        $this->assertSame('order.updated', $event->type);

        $expected = OrderPresenter::toArray($order->fresh());
        $actual = $event->payload;

        $this->assertArrayHasKey('stream_cursor', $actual);
        unset($expected['stream_cursor'], $actual['stream_cursor']);

        $this->assertEquals($expected, $actual);

        // Точечно — то, что раньше проверялось изолированно и обязано
        // остаться верным после перехода на презентер.
        $this->assertSame($order->id, $actual['id']);
        $this->assertSame('created', $actual['status']);
        $this->assertSame($order->total_minor, $actual['total_minor']);
        $this->assertSame($offer->id, $actual['offer_id']);

        // И собственно расширение контракта задачи 4a — то, чего в узком
        // наборе полей не было вовсе.
        $this->assertSame($offer->id, $actual['offer']['offer_id']);
        $this->assertNull($actual['reservation'], 'у заказа из этого теста нет единицы склада');
        $this->assertFalse($actual['refund_required']);
    }

    public function test_publish_order_returns_null_for_missing_order(): void
    {
        $this->assertNull($this->bus->publishOrder('ord_does_not_exist'));
    }
}
