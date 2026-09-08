<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Order;
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

        $this->postJson("/api/dev/reservations/{$orderId}/expire")->assertOk();
        $this->artisan('reservations:release')->assertSuccessful();

        $this->assertSame('reservation_expired', Order::query()->findOrFail($orderId)->status->value);
    }
}
