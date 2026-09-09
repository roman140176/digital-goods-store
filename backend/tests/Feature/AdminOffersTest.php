<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Orders\OrderStatus;
use App\Jobs\DeliverOrder;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderAudit;
use App\Models\StockUnit;
use App\Models\StreamEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminOffersTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'admin-secret-token';

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->offer = Offer::query()->orderBy('id')->firstOrFail();
    }

    public function test_price_change_publishes_an_event(): void
    {
        StreamEvent::query()->delete();

        $this->post("/admin/offers/{$this->offer->id}/price?token=".self::TOKEN,
            ['price_minor' => 111100])->assertRedirect();

        $this->assertSame(111100, $this->offer->refresh()->price_minor);

        $event = StreamEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('offer.updated', $event->type);
        $this->assertSame(111100, $event->payload['price_minor']);
    }

    /**
     * A21: (int) на голом input тихо приводил "12.7" к 12 вместо отказа —
     * integer-правило обязано отклонить нецелый ввод понятной 422, а не
     * округлить его молча, и цена предложения обязана остаться прежней.
     * Форма ответа — respond()'а этой ручки (message/updated), не стандартный
     * errors-конверт Laravel: см. test_price_shows_a_visible_message_for_a_
     * non_json_invalid_request ниже и её докблок про единственный канал
     * сообщений страницы.
     */
    public function test_price_rejects_a_non_integer_value(): void
    {
        $originalPrice = $this->offer->price_minor;

        $this->postJson("/admin/offers/{$this->offer->id}/price?token=".self::TOKEN,
            ['price_minor' => '12.7'])
            ->assertStatus(422)
            ->assertJsonPath('updated', false)
            ->assertJsonPath('message', 'Цена должна быть целым числом больше нуля.');

        $this->assertSame($originalPrice, $this->offer->refresh()->price_minor);
    }

    /**
     * Фикс-раунд 1, Important 2: единственный человеческий вход в эту ручку —
     * обычная HTML-форма (resources/views/admin/orders.blade.php), обычный
     * POST без fetch и без Accept: application/json — $request->expectsJson()
     * для неё ложно. До валидации Validator::make()+respond() отказ уходил
     * бы через $request->validate() в редирект с ошибками в сессии, которые
     * некому показать (@error и $errors нигде в blade не рендерятся,
     * единственный канал — session('status') в orders.blade.php:27-28):
     * админ увидел бы молчаливую перезагрузку страницы без единого слова.
     */
    public function test_price_shows_a_visible_message_for_a_non_json_invalid_request(): void
    {
        $originalPrice = $this->offer->price_minor;

        $this->post("/admin/offers/{$this->offer->id}/price?token=".self::TOKEN,
            ['price_minor' => '12.7'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Цена должна быть целым числом больше нуля.');

        $this->assertSame($originalPrice, $this->offer->refresh()->price_minor);
    }

    public function test_leave_one_keeps_exactly_one_free_unit(): void
    {
        StockUnit::query()->create(['offer_id' => $this->offer->id, 'state' => 'available']);

        $this->post("/admin/offers/{$this->offer->id}/leave-one?token=".self::TOKEN)
            ->assertRedirect();

        $this->assertSame(1, StockUnit::query()->where('offer_id', $this->offer->id)
            ->where('state', 'available')->count());
    }

    /**
     * «Оставить одну» обязано работать и от нуля: без единицы, добавленной в
     * этой ветке, гонку за последнюю единицу заново не воспроизвести на
     * предложении, которое уже полностью раскупили (available=0 — обычное
     * состояние «горячего» товара после его собственной демонстрации).
     */
    public function test_leave_one_adds_a_unit_when_none_are_free(): void
    {
        StockUnit::query()->where('offer_id', $this->offer->id)->delete();

        $this->post("/admin/offers/{$this->offer->id}/leave-one?token=".self::TOKEN)
            ->assertRedirect();

        $this->assertSame(1, StockUnit::query()->where('offer_id', $this->offer->id)
            ->where('state', 'available')->count());
    }

    public function test_stock_change_never_touches_reserved_or_sold_units(): void
    {
        StockUnit::query()->where('offer_id', $this->offer->id)->limit(1)
            ->update(['state' => 'sold', 'sold_at' => now()]);

        // Настоящая, а не имитированная бронь: reserved_order_id — внешний
        // ключ на реальный заказ, иначе регрессия, которая расширила бы
        // DELETE на reserved, осталась бы незамеченной — имя теста обещает
        // защиту ОБОИХ состояний, не только sold.
        $reservingOrder = $this->createOrder(OrderStatus::Created);
        StockUnit::query()->create([
            'offer_id' => $this->offer->id,
            'state' => 'reserved',
            'reserved_order_id' => $reservingOrder->id,
            'reserved_until' => now()->addMinutes(5),
        ]);

        $this->post("/admin/offers/{$this->offer->id}/stock?token=".self::TOKEN,
            ['units' => 0])->assertRedirect();

        // Проданную единицу удалять нельзя: на неё ссылается выданный заказ.
        $this->assertSame(1, StockUnit::query()->where('offer_id', $this->offer->id)
            ->where('state', 'sold')->count());
        // Забронированную — тоже: она принадлежит живому покупателю с
        // незавершённым оформлением, а не остатку, которым распоряжается админ.
        $this->assertSame(1, StockUnit::query()->where('offer_id', $this->offer->id)
            ->where('state', 'reserved')->count());
    }

    /**
     * A21: та же причина, что и у price() — (int) на "abc" тихо давал 0
     * вместо отказа. Нечисловой ввод обязан быть отклонён понятной 422, а
     * не тихо обнулить остаток.
     */
    public function test_stock_rejects_a_non_integer_value(): void
    {
        $originalAvailable = StockUnit::query()->where('offer_id', $this->offer->id)
            ->where('state', 'available')->count();

        $this->postJson("/admin/offers/{$this->offer->id}/stock?token=".self::TOKEN,
            ['units' => 'abc'])
            ->assertStatus(422)
            ->assertJsonPath('updated', false)
            ->assertJsonPath('message', 'Остаток должен быть целым числом не меньше нуля.');

        $this->assertSame($originalAvailable, StockUnit::query()->where('offer_id', $this->offer->id)
            ->where('state', 'available')->count());
    }

    /**
     * Фикс-раунд 1, Important 2: та же причина, что и у
     * test_price_shows_a_visible_message_for_a_non_json_invalid_request —
     * обычная HTML-форма без Accept: application/json не должна тихо
     * перезагрузиться без сообщения.
     */
    public function test_stock_shows_a_visible_message_for_a_non_json_invalid_request(): void
    {
        $originalAvailable = StockUnit::query()->where('offer_id', $this->offer->id)
            ->where('state', 'available')->count();

        $this->post("/admin/offers/{$this->offer->id}/stock?token=".self::TOKEN,
            ['units' => 'abc'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Остаток должен быть целым числом не меньше нуля.');

        $this->assertSame($originalAvailable, StockUnit::query()->where('offer_id', $this->offer->id)
            ->where('state', 'available')->count());
    }

    public function test_admin_endpoints_require_the_token(): void
    {
        $this->post("/admin/offers/{$this->offer->id}/price", ['price_minor' => 1])
            ->assertStatus(403);
    }

    public function test_toggle_hides_offer_and_publishes_offer_gone(): void
    {
        StreamEvent::query()->delete();

        $this->post("/admin/offers/{$this->offer->id}/toggle?token=".self::TOKEN)
            ->assertRedirect();

        $this->assertSame('hidden', $this->offer->refresh()->status);

        $event = StreamEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('offer.gone', $event->type);
        $this->assertSame($this->offer->id, $event->payload['offer_id']);
    }

    /**
     * Toggle — переключатель, а не идемпотентное «скрыть»: второе нажатие
     * (например, потому что администратор не заметил, что кнопка уже
     * поменяла подпись) обязано вернуть предложение в active, а не упасть и
     * не оставить лишний след в журнале сверх одного события на нажатие.
     */
    public function test_toggle_twice_switches_back_without_duplicate_events(): void
    {
        StreamEvent::query()->delete();

        $this->post("/admin/offers/{$this->offer->id}/toggle?token=".self::TOKEN)
            ->assertRedirect();
        $this->assertSame('hidden', $this->offer->refresh()->status);

        $this->post("/admin/offers/{$this->offer->id}/toggle?token=".self::TOKEN)
            ->assertRedirect();
        $this->assertSame('active', $this->offer->refresh()->status);

        // Ровно два события на два нажатия — ни одно не потерялось и не
        // задвоилось.
        $events = StreamEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame('offer.gone', $events[0]->type);
        $this->assertSame('offer.updated', $events[1]->type);
        $this->assertSame('active', $events[1]->payload['status']);
    }

    /**
     * Решение задачи 13: единственный ручной путь вернуть в выдачу заказ,
     * который был оплачен, когда товара уже не было (out_of_stock +
     * refund_required, см. ApplyPaymentEvent::applyPaidWithoutStock). Склад
     * пополнили — повторная выдача обязана сама захватить единицу и снять
     * отметку о возврате, а не просто повторить попытку похода к поставщику
     * вхолостую.
     */
    public function test_redelivery_reclaims_a_unit_for_refund_required_order(): void
    {
        Queue::fake();

        // Имитируем реальный сценарий: на момент оплаты свободных единиц уже
        // не было (иначе applyPaidWithoutStock вообще не наступил бы).
        StockUnit::query()->where('offer_id', $this->offer->id)->delete();

        $order = $this->createOrder(OrderStatus::OutOfStock, refundRequired: true);

        // Склад пополнили — ровно то действие, которое админ выполнит на
        // демонстрации перед повторной выдачей.
        $this->post("/admin/offers/{$this->offer->id}/stock?token=".self::TOKEN,
            ['units' => 1])->assertRedirect();

        $this->postJson('/admin/orders/'.$order->id.'/redeliver?token='.self::TOKEN)
            ->assertOk()
            ->assertJsonPath('redelivered', true);

        $this->assertSame(0, StockUnit::query()->where('offer_id', $this->offer->id)
            ->where('state', 'available')->count());
        $this->assertSame(1, StockUnit::query()->where('offer_id', $this->offer->id)
            ->where('state', 'sold')
            ->where('reserved_order_id', $order->id)->count());
        $this->assertFalse((bool) $order->refresh()->refund_required);
        Queue::assertPushed(DeliverOrder::class);
    }

    /**
     * Пополнения нет — ответ обязан честно сказать, что выдавать нечего, и
     * не создавать задачу выдачи: иначе воркер сходил бы к поставщику вхолостую,
     * а refund_required тихо потерялся бы без реально проданного товара.
     */
    public function test_redelivery_without_stock_keeps_refund_required_and_does_nothing(): void
    {
        Queue::fake();

        StockUnit::query()->where('offer_id', $this->offer->id)->delete();

        $order = $this->createOrder(OrderStatus::OutOfStock, refundRequired: true);

        $this->postJson('/admin/orders/'.$order->id.'/redeliver?token='.self::TOKEN)
            ->assertStatus(409)
            ->assertJsonPath('redelivered', false);

        $this->assertTrue((bool) $order->refresh()->refund_required);
        $this->assertSame(OrderStatus::OutOfStock->value, $order->status->value);
        Queue::assertNothingPushed();
    }

    /**
     * Второе нажатие «Выдать повторно» после того, как первое уже захватило
     * единицу и сняло refund_required: реентерабельность sellForOrder не
     * даёт этому пути 500 (единица уже sold — sellForOrder находит её через
     * тот же reserved_order_id и отвечает SoldHeld, не пытаясь захватывать
     * вторую), а внешний guard не даёт задвоиться записи аудита.
     */
    public function test_repeated_redelivery_after_reclaim_is_idempotent(): void
    {
        Queue::fake();

        StockUnit::query()->where('offer_id', $this->offer->id)->delete();

        $order = $this->createOrder(OrderStatus::OutOfStock, refundRequired: true);

        $this->post("/admin/offers/{$this->offer->id}/stock?token=".self::TOKEN,
            ['units' => 1])->assertRedirect();

        $this->postJson('/admin/orders/'.$order->id.'/redeliver?token='.self::TOKEN)
            ->assertOk()->assertJsonPath('redelivered', true);

        // Второе нажатие: не 500, остаётся успешным, ничего не портит.
        $this->postJson('/admin/orders/'.$order->id.'/redeliver?token='.self::TOKEN)
            ->assertOk()->assertJsonPath('redelivered', true);

        $this->assertFalse((bool) $order->refresh()->refund_required);
        $this->assertSame(1, StockUnit::query()->where('offer_id', $this->offer->id)
            ->where('state', 'sold')->where('reserved_order_id', $order->id)->count());
        $this->assertSame(1, OrderAudit::query()->where('order_id', $order->id)
            ->where('action', 'refund_reclaimed')->count());
    }

    private function createOrder(OrderStatus $status, bool $refundRequired = false): Order
    {
        return Order::query()->create([
            'id' => 'ord_'.Str::lower((string) Str::ulid()),
            'sku' => $this->offer->product_sku,
            'offer_id' => $this->offer->id,
            'amount_minor' => $this->offer->price_minor,
            'discount_minor' => 0,
            'total_minor' => $this->offer->price_minor,
            'currency' => $this->offer->currency,
            'status' => $status,
            'idempotency_key' => (string) Str::uuid(),
            'refund_required' => $refundRequired,
        ]);
    }
}
