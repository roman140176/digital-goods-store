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
        $promo = Promocode::query()->findOrFail('WELCOME10');

        $this->assertSame(3990, $this->promo->discountFor($promo, 39900));
        $this->assertSame(2990, $this->promo->discountFor($promo, 29900));
    }

    public function test_fixed_discount_never_exceeds_price(): void
    {
        $promo = Promocode::query()->findOrFail('GG500');

        $this->assertSame(50000, $this->promo->discountFor($promo, 129000));
        $this->assertSame(29900, $this->promo->discountFor($promo, 29900), 'скидка не может превышать цену');
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

    public function test_release_returns_usage_and_keeps_audit_trail(): void
    {
        $this->promo->reserve('LIMIT3', 129000, 'ord_release', 'RUB');
        $this->assertSame(1, Promocode::query()->find('LIMIT3')?->used_count);

        $this->promo->release('LIMIT3', 'ord_release');

        $this->assertSame(0, Promocode::query()->find('LIMIT3')?->used_count);
        $this->assertDatabaseHas('promo_redemptions', ['order_id' => 'ord_release', 'code' => 'LIMIT3']);
        $this->assertDatabaseMissing('promo_redemptions', ['order_id' => 'ord_release', 'released_at' => null]);
    }

    public function test_release_is_idempotent(): void
    {
        $this->promo->reserve('LIMIT3', 129000, 'ord_twice', 'RUB');

        $this->promo->release('LIMIT3', 'ord_twice');
        $this->promo->release('LIMIT3', 'ord_twice');

        $this->assertSame(0, Promocode::query()->find('LIMIT3')?->used_count);
    }
}
