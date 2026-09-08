<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Realtime\EventCursor;
use PHPUnit\Framework\TestCase;

final class EventCursorTest extends TestCase
{
    public function test_contiguous_moves_only_through_an_unbroken_chain(): void
    {
        $cursor = new EventCursor(10);

        $cursor->observe(11);
        $cursor->observe(12);
        $cursor->advance(0.0);
        $this->assertSame(12, $cursor->contiguous());

        // 13 ещё не закоммичена: последовательность выдаёт номер до коммита,
        // поэтому 14 может стать видимой первой. Курсор обязан подождать.
        $cursor->observe(14);
        $cursor->advance(0.0);
        $this->assertSame(12, $cursor->contiguous());

        $cursor->observe(13);
        $cursor->advance(0.0);
        $this->assertSame(14, $cursor->contiguous());
    }

    public function test_a_hole_older_than_the_grace_period_is_skipped(): void
    {
        $cursor = new EventCursor(10);

        $cursor->observe(12);
        $cursor->advance(0.0);
        $this->assertSame(10, $cursor->contiguous());

        // Номер 11 мог быть выделен транзакцией, которая откатилась: он не
        // появится никогда, и ждать его вечно нельзя.
        $cursor->advance(3.5);
        $this->assertSame(12, $cursor->contiguous());
    }

    public function test_delivered_ids_are_remembered_to_suppress_duplicates(): void
    {
        $cursor = new EventCursor(0);

        $cursor->observe(5);
        $this->assertTrue($cursor->wasDelivered(5));
        $this->assertFalse($cursor->wasDelivered(6));
    }

    public function test_the_memory_of_delivered_ids_is_bounded(): void
    {
        $cursor = new EventCursor(0);

        for ($id = 1; $id <= 1500; $id++) {
            $cursor->observe($id);
            $cursor->advance(0.0);
        }

        // Буфер кольцевой: иначе долгоживущий процесс копил бы id вечно.
        $this->assertFalse($cursor->wasDelivered(1));
        $this->assertTrue($cursor->wasDelivered(1500));
    }
}
