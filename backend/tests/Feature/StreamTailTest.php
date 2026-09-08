<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Realtime\EventBus;
use App\Domain\Realtime\StreamHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StreamTailTest extends TestCase
{
    use RefreshDatabase;

    public function test_tail_returns_only_events_after_the_cursor_in_order(): void
    {
        $bus = $this->app->make(EventBus::class);
        $hub = $this->app->make(StreamHub::class);

        $first = $bus->publish('catalog', 'offer.updated', ['offer_id' => 1]);
        $second = $bus->publish('catalog', 'offer.updated', ['offer_id' => 2]);
        $third = $bus->publish('order:ord_x', 'order.updated', ['id' => 'ord_x']);

        $tail = $hub->tail($first, ['catalog']);

        $this->assertSame([$second], array_column($tail, 'id'));

        $both = $hub->tail($first, ['catalog', 'order:ord_x']);
        $this->assertSame([$second, $third], array_column($both, 'id'));
    }

    public function test_min_event_id_tells_when_a_cursor_is_too_old(): void
    {
        $bus = $this->app->make(EventBus::class);
        $hub = $this->app->make(StreamHub::class);

        $id = $bus->publish('catalog', 'offer.updated', ['offer_id' => 1]);

        $this->assertSame($id, $hub->minEventId());
    }
}
