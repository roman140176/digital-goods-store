<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Order;
use App\Models\Promocode;
use App\Models\PromoRedemption;
use App\Models\Seller;
use App\Models\StockUnit;
use App\Models\StreamEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ReservationExpiryTest extends TestCase
{
    use RefreshDatabase;

    private Offer $hot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();

        $this->hot = Offer::query()->where('product_sku', 'KEY-CS2-PRIME')
            ->orderBy('price_minor')->firstOrFail();
    }

    private function order(string $key): string
    {
        return (string) $this->postJson('/api/orders', ['offer_id' => $this->hot->id],
            ['Idempotency-Key' => $key])->assertCreated()->json('id');
    }

    /**
     * Переставляет дедлайн брони в прошлое напрямую, а не через $this->travel().
     *
     * RefreshDatabase оборачивает весь тест в одну транзакцию Postgres, а SQL
     * now() внутри транзакции — это transaction_timestamp(), застывший на
     * её начале: `BEGIN; SELECT now(); pg_sleep(2); SELECT now();` внутри
     * одной транзакции возвращает ОДНО И ТО ЖЕ значение (проверено вручную,
     * см. отчёт задачи 5). $this->travel() двигает только часы PHP/Carbon —
     * на SQL now(), которым написан reserved_until и которым его же читает
     * releaseExpired(), это не влияет вообще никак, сколько секунд ни
     * прокручивай. Единственный работающий способ — как и в соседнем
     * ReservationTest::test_expired_reservation_of_another_order_is_reused —
     * записать явное прошлое значение самим PHP-таймстампом. Запас в 10
     * минут, а не секунда-в-секунду к границе TTL: тот же принцип, что и в
     * предупреждении задачи про флаки-тест первого этапа — подгонка на
     * грани ловит гонку, а не проверяет логику.
     */
    private function backdateReservation(string $orderId): void
    {
        StockUnit::query()->where('reserved_order_id', $orderId)
            ->update(['reserved_until' => now()->subMinutes(10)]);
    }

    public function test_expired_reservation_returns_the_unit_and_kills_the_order(): void
    {
        $orderId = $this->order('exp-1');

        $this->backdateReservation($orderId);
        $this->artisan('reservations:release')->assertSuccessful();

        $this->assertSame('available', StockUnit::query()
            ->where('offer_id', $this->hot->id)->firstOrFail()->state);
        $this->assertSame('reservation_expired', Order::query()->findOrFail($orderId)->status->value);
    }

    public function test_release_publishes_the_offer_state_so_everyone_sees_it_return(): void
    {
        $orderId = $this->order('exp-2');
        StreamEvent::query()->delete();

        $this->backdateReservation($orderId);
        $this->artisan('reservations:release')->assertSuccessful();

        $event = StreamEvent::query()->where('type', 'offer.updated')->latest('id')->firstOrFail();

        $this->assertSame($this->hot->id, $event->payload['offer_id']);
        $this->assertSame(1, $event->payload['available']);
    }

    public function test_paid_order_keeps_its_unit_after_the_deadline(): void
    {
        $orderId = $this->order('exp-3');
        Order::query()->whereKey($orderId)->update(['status' => 'paid']);

        $this->backdateReservation($orderId);
        $this->artisan('reservations:release')->assertSuccessful();

        // Оплаченный заказ держит единицу до выдачи: возврат её в продажу
        // означал бы оплаченный заказ без товара.
        $this->assertSame('reserved', StockUnit::query()
            ->where('offer_id', $this->hot->id)->firstOrFail()->state);
        $this->assertSame('paid', Order::query()->findOrFail($orderId)->status->value);
    }

    public function test_dev_endpoint_expires_a_reservation_immediately(): void
    {
        $orderId = $this->order('exp-4');

        $this->postJson("/api/dev/reservations/{$orderId}/expire")
            ->assertOk()
            ->assertJsonPath('expired', true);

        $this->artisan('reservations:release')->assertSuccessful();

        $this->assertSame('reservation_expired', Order::query()->findOrFail($orderId)->status->value);

        // Бронь уже снята планировщиком выше — просрочивать здесь нечего, и
        // признак обязан честно показать false, а не true просто по факту
        // 200 OK: сценарии приёмки различают исход именно по этому полю.
        $this->postJson("/api/dev/reservations/{$orderId}/expire")
            ->assertOk()
            ->assertJsonPath('expired', false);
    }

    /**
     * Minor B ревью: все четыре теста выше держат по одному заказу за раз,
     * а циклы с array_unique() в ReleaseExpiredReservations нужно проверить
     * именно на N > 1 — включая дедупликацию: предложение с двумя истёкшими
     * юнитами обязано получить одно offer.updated, а не два.
     */
    public function test_multiple_expiries_in_one_tick_expire_every_order_and_publish_each_offer_once(): void
    {
        $seller = Seller::query()->create(['name' => 'Второй тест-продавец', 'rating' => 4.5]);
        $twoUnitOffer = Offer::query()->create([
            'product_sku' => 'KEY-CS2-PRIME',
            'seller_id' => $seller->id,
            'supplier_id' => 'a',
            'price_minor' => 500000,
            'currency' => 'RUB',
            'status' => 'active',
        ]);
        StockUnit::query()->create(['offer_id' => $twoUnitOffer->id, 'state' => 'available']);
        StockUnit::query()->create(['offer_id' => $twoUnitOffer->id, 'state' => 'available']);

        // Два заказа на одно и то же (новое) предложение — оба его юнита.
        $orderA = (string) $this->postJson('/api/orders', ['offer_id' => $twoUnitOffer->id],
            ['Idempotency-Key' => 'multi-a'])->assertCreated()->json('id');
        $orderB = (string) $this->postJson('/api/orders', ['offer_id' => $twoUnitOffer->id],
            ['Idempotency-Key' => 'multi-b'])->assertCreated()->json('id');

        // Третий заказ — на СОВСЕМ ДРУГОЕ предложение: тик не должен
        // останавливаться на первом затронутом предложении.
        $orderC = $this->order('multi-c');

        StockUnit::query()->whereIn('reserved_order_id', [$orderA, $orderB, $orderC])
            ->update(['reserved_until' => now()->subMinutes(10)]);
        StreamEvent::query()->delete();

        $this->artisan('reservations:release')->assertSuccessful();

        foreach ([$orderA, $orderB, $orderC] as $orderId) {
            $this->assertSame('reservation_expired', Order::query()->findOrFail($orderId)->status->value);
        }

        $this->assertSame(2, StockUnit::query()->where('offer_id', $twoUnitOffer->id)
            ->where('state', 'available')->count());

        $offerEvents = StreamEvent::query()->where('type', 'offer.updated')->get();

        // Оба юнита twoUnitOffer истекли в ОДНОМ тике — событие обязано
        // уйти один раз, а не по разу на каждый освободившийся юнит.
        $this->assertCount(1, $offerEvents->filter(
            fn (StreamEvent $event): bool => $event->payload['offer_id'] === $twoUnitOffer->id,
        ));
        $this->assertCount(1, $offerEvents->filter(
            fn (StreamEvent $event): bool => $event->payload['offer_id'] === $this->hot->id,
        ));
    }

    /**
     * Minor C ревью: по спеке (6.4) слот промокода истёкшего заказа сгорает
     * — иначе поздняя оплата истёкшей брони получила бы скидку без реально
     * занятого слота лимита. Поведение сейчас обеспечено просто отсутствием
     * кода (ReleaseExpiredReservations не трогает promocodes/promo_redemptions
     * вовсе) — этот тест защищает от того, что будущая правка случайно
     * начнёт освобождать слот при истечении брони.
     */
    public function test_promo_slot_is_not_released_when_the_reservation_expires(): void
    {
        $orderId = (string) $this->postJson('/api/orders',
            ['offer_id' => $this->hot->id, 'promo_code' => 'WELCOME10'],
            ['Idempotency-Key' => 'exp-promo'])->assertCreated()->json('id');

        $this->backdateReservation($orderId);
        $this->artisan('reservations:release')->assertSuccessful();

        $this->assertSame('reservation_expired', Order::query()->findOrFail($orderId)->status->value);

        $this->assertSame(1, Promocode::query()->findOrFail('WELCOME10')->used_count);
        $this->assertSame(1, PromoRedemption::query()->where('order_id', $orderId)->count());
        $this->assertNull(PromoRedemption::query()->where('order_id', $orderId)->value('released_at'));
    }
}
