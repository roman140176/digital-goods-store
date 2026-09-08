<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Promo\PromoService;
use App\Domain\Promo\PromoUnavailable;
use App\Models\Promocode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PromoServiceTest extends TestCase
{
    use RefreshDatabase;

    private PromoService $promo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->promo = app(PromoService::class);
    }

    public function test_percent_discount_is_rounded_down(): void
    {
        $this->assertSame(3990, $this->promo->discountFor('WELCOME10', 39900, 'RUB'));
        $this->assertSame(2990, $this->promo->discountFor('WELCOME10', 29900, 'RUB'));
    }

    public function test_fixed_discount_never_exceeds_price(): void
    {
        $this->assertSame(50000, $this->promo->discountFor('GG500', 129000, 'RUB'));
        $this->assertSame(29900, $this->promo->discountFor('GG500', 29900, 'RUB'), 'скидка не может превышать цену');
    }

    public function test_reserve_respects_limit(): void
    {
        $this->promo->reserve('ONCEONLY', 129000, 'ord_first', 'RUB');

        $this->expectException(PromoUnavailable::class);
        $this->promo->reserve('ONCEONLY', 129000, 'ord_second', 'RUB');
    }

    public function test_unknown_code_is_rejected(): void
    {
        $this->expectException(PromoUnavailable::class);
        $this->promo->reserve('NOPE', 129000, 'ord_x', 'RUB');
    }

    public function test_currency_mismatch_is_rejected(): void
    {
        $this->expectException(PromoUnavailable::class);
        $this->promo->reserve('GG500', 129000, 'ord_x', 'USD');
    }

    public function test_reserved_usage_is_recorded_and_counted_once(): void
    {
        $discount = $this->promo->reserve('LIMIT3', 129000, 'ord_audit', 'RUB');

        $this->assertSame(32250, $discount);
        $this->assertSame(1, Promocode::query()->find('LIMIT3')?->used_count);
        $this->assertDatabaseHas('promo_redemptions', [
            'order_id' => 'ord_audit',
            'code' => 'LIMIT3',
            'discount_minor' => 32250,
        ]);
    }

    public function test_failed_reservation_does_not_consume_the_limit(): void
    {
        $this->promo->reserve('ONCEONLY', 129000, 'ord_taken', 'RUB');

        try {
            $this->promo->reserve('ONCEONLY', 129000, 'ord_rejected', 'RUB');
            $this->fail('ожидался отказ по лимиту');
        } catch (PromoUnavailable $e) {
            $this->assertSame('limit_reached', $e->why);
        }

        $promo = Promocode::query()->findOrFail('ONCEONLY');
        $this->assertSame(1, $promo->used_count, 'счётчик не должен уйти выше лимита');
        $this->assertDatabaseMissing('promo_redemptions', ['order_id' => 'ord_rejected']);
    }
}
