<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Promocode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CreateOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
    }

    public function test_repeated_request_with_same_idempotency_key_creates_one_order(): void
    {
        $headers = ['Idempotency-Key' => 'double-click-1'];

        $first = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'], $headers);
        $second = $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'], $headers);

        $first->assertCreated();
        $second->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_request_without_idempotency_key_is_rejected(): void
    {
        $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'idempotency_key_required');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_server_calculates_percent_discount(): void
    {
        $response = $this->postJson(
            '/api/orders',
            ['sku' => 'KEY-CS2-PRIME', 'promo_code' => 'WELCOME10'],
            ['Idempotency-Key' => 'promo-percent'],
        );

        $response->assertCreated()
            ->assertJsonPath('amount_minor', 129000)
            ->assertJsonPath('discount_minor', 12900)
            ->assertJsonPath('total_minor', 116100);
    }

    public function test_server_calculates_fixed_discount(): void
    {
        $response = $this->postJson(
            '/api/orders',
            ['sku' => 'KEY-CS2-PRIME', 'promo_code' => 'GG500'],
            ['Idempotency-Key' => 'promo-amount'],
        );

        $response->assertCreated()
            ->assertJsonPath('discount_minor', 50000)
            ->assertJsonPath('total_minor', 79000);
    }

    public function test_client_cannot_influence_price_or_discount(): void
    {
        $response = $this->postJson(
            '/api/orders',
            ['sku' => 'KEY-CS2-PRIME', 'amount_minor' => 1, 'discount_minor' => 128999, 'total_minor' => 1],
            ['Idempotency-Key' => 'price-tamper'],
        );

        $response->assertCreated()
            ->assertJsonPath('amount_minor', 129000)
            ->assertJsonPath('discount_minor', 0)
            ->assertJsonPath('total_minor', 129000);
    }

    public function test_double_click_with_promo_consumes_single_use(): void
    {
        $headers = ['Idempotency-Key' => 'promo-double-click'];

        $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME', 'promo_code' => 'LIMIT3'], $headers)->assertCreated();
        $this->postJson('/api/orders', ['sku' => 'KEY-CS2-PRIME', 'promo_code' => 'LIMIT3'], $headers)->assertOk();

        $this->assertSame(1, Promocode::query()->find('LIMIT3')?->used_count);
    }

    public function test_exhausted_promo_is_rejected(): void
    {
        $this->postJson('/api/orders', ['sku' => 'KEY-GTA5', 'promo_code' => 'ONCEONLY'], ['Idempotency-Key' => 'once-1'])
            ->assertCreated();

        $this->postJson('/api/orders', ['sku' => 'KEY-GTA5', 'promo_code' => 'ONCEONLY'], ['Idempotency-Key' => 'once-2'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'limit_reached');

        $this->assertSame(1, Promocode::query()->find('ONCEONLY')?->used_count);
    }

    public function test_unknown_sku_is_rejected(): void
    {
        $this->postJson('/api/orders', ['sku' => 'NO-SUCH-SKU'], ['Idempotency-Key' => 'unknown-sku'])
            ->assertStatus(422);
    }
}
