<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Delivery\DeliveryState;
use App\Domain\Delivery\IssueOrderCode;
use App\Domain\Delivery\SupplierOutcome;
use App\Domain\Delivery\SupplierRegistry;
use App\Domain\Orders\OrderStatus;
use App\Models\Delivery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FakeSupplier;
use Tests\TestCase;

final class IssueOrderCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function issueWith(FakeSupplier ...$suppliers): IssueOrderCode
    {
        $map = [];
        foreach ($suppliers as $supplier) {
            $map[$supplier->id()] = $supplier;
        }

        return new IssueOrderCode(new SupplierRegistry($map), attemptsPerSupplier: 3, lockSeconds: 45);
    }

    private function paidOrder(): Delivery
    {
        $order = Order::query()->create([
            'id' => 'ord_'.Str::lower((string) Str::ulid()),
            'sku' => 'KEY-CS2-PRIME',
            'amount_minor' => 129000,
            'discount_minor' => 0,
            'total_minor' => 129000,
            'currency' => 'RUB',
            'status' => OrderStatus::Paid,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return Delivery::query()->create([
            'order_id' => $order->id,
            'request_id' => Delivery::requestIdFor($order->id),
            'state' => DeliveryState::Pending->value,
            'attempts' => 0,
        ]);
    }

    public function test_code_from_primary_supplier_is_attached_to_order(): void
    {
        $delivery = $this->paidOrder();
        $a = new FakeSupplier('a', [SupplierOutcome::ok('AAAA-1111-ZZZZ')]);
        $b = new FakeSupplier('b', []);

        ($this->issueWith($a, $b))($delivery->id);

        $order = $delivery->order->refresh();
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame('AAAA-1111-ZZZZ', $order->delivered_code);
        $this->assertSame('a', $order->delivered_by);
        $this->assertSame(DeliveryState::Done, $delivery->refresh()->state);
        $this->assertCount(0, $b->calls, 'резервный поставщик не должен вызываться');
    }

    /**
     * Ловушка таймаута: ответа не было, поставщик мог выдать код.
     * Уход к резервному израсходовал бы второй ключ, поэтому он запрещён.
     */
    public function test_missing_response_never_falls_over_to_backup_supplier(): void
    {
        $delivery = $this->paidOrder();
        $a = new FakeSupplier('a', [
            SupplierOutcome::ambiguous('timeout'),
            SupplierOutcome::ambiguous('timeout'),
            SupplierOutcome::ambiguous('timeout'),
        ]);
        $b = new FakeSupplier('b', [SupplierOutcome::ok('BBBB-2222-YYYY')]);

        ($this->issueWith($a, $b))($delivery->id);

        $order = $delivery->order->refresh();
        $this->assertSame(OrderStatus::DeliveryFailed, $order->status);
        $this->assertNull($order->delivered_code);
        $this->assertCount(0, $b->calls, 'при неизвестном исходе резервный поставщик недопустим');
        $this->assertCount(3, $a->calls, 'повторы уходят тому же поставщику');
        $this->assertSame(['req_'.$order->id], array_unique($a->calls), 'request_id не меняется между попытками');
    }

    /** Ответ получен и он однозначный: кода нет, резервный поставщик безопасен. */
    public function test_definitive_out_of_stock_falls_over_to_backup_supplier(): void
    {
        $delivery = $this->paidOrder();
        $a = new FakeSupplier('a', [SupplierOutcome::outOfStock()]);
        $b = new FakeSupplier('b', [SupplierOutcome::ok('BBBB-3333-XXXX')]);

        ($this->issueWith($a, $b))($delivery->id);

        $order = $delivery->order->refresh();
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame('BBBB-3333-XXXX', $order->delivered_code);
        $this->assertSame('b', $order->delivered_by);
        $this->assertCount(1, $a->calls, 'при пустом складе повторять бессмысленно');
    }

    public function test_both_suppliers_out_of_stock_leaves_order_recoverable(): void
    {
        $delivery = $this->paidOrder();
        $a = new FakeSupplier('a', [SupplierOutcome::outOfStock()]);
        $b = new FakeSupplier('b', [SupplierOutcome::outOfStock()]);

        ($this->issueWith($a, $b))($delivery->id);

        $order = $delivery->order->refresh();
        $this->assertSame(OrderStatus::OutOfStock, $order->status);
        $this->assertTrue($order->status->isRecoverable());
        $this->assertNull($order->delivered_code);
        $this->assertSame(DeliveryState::OutOfStock, $delivery->refresh()->state);
    }

    public function test_error_response_from_both_suppliers_leaves_order_recoverable(): void
    {
        $delivery = $this->paidOrder();
        $a = new FakeSupplier('a', array_fill(0, 3, SupplierOutcome::errored('http 500')));
        $b = new FakeSupplier('b', array_fill(0, 3, SupplierOutcome::errored('http 500')));

        ($this->issueWith($a, $b))($delivery->id);

        $order = $delivery->order->refresh();
        $this->assertSame(OrderStatus::DeliveryFailed, $order->status);
        $this->assertTrue($order->status->isRecoverable());
        $this->assertCount(3, $a->calls);
        $this->assertCount(3, $b->calls);
    }

    public function test_second_pass_over_delivered_order_issues_nothing(): void
    {
        $delivery = $this->paidOrder();
        $a = new FakeSupplier('a', [SupplierOutcome::ok('AAAA-4444-WWWW')]);
        $b = new FakeSupplier('b', [SupplierOutcome::ok('BBBB-5555-VVVV')]);

        $issue = $this->issueWith($a, $b);
        $issue($delivery->id);
        $issue($delivery->id);

        $order = $delivery->order->refresh();
        $this->assertSame('AAAA-4444-WWWW', $order->delivered_code);
        $this->assertCount(1, $a->calls, 'повторный проход не обращается к поставщику');
        $this->assertCount(0, $b->calls);
    }

    public function test_delivery_already_in_progress_is_not_claimed_twice(): void
    {
        $delivery = $this->paidOrder();
        $delivery->forceFill([
            'state' => DeliveryState::InProgress->value,
            'locked_until' => now()->addSeconds(30),
        ])->save();

        $a = new FakeSupplier('a', [SupplierOutcome::ok('AAAA-6666-UUUU')]);

        ($this->issueWith($a))($delivery->id);

        $this->assertCount(0, $a->calls, 'аренда задачи ещё не истекла');
        $this->assertNull($delivery->order->refresh()->delivered_code);
    }
}
