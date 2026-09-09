<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Delivery\SupplierRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeSupplier;
use Tests\TestCase;

final class SyncSupplierStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * A7: /restock молча усекает count верхней границей (заглушка склада) —
     * команда обязана сверить фактически добавленное с запрошенным и
     * предупредить, а не молчать. Сид даёт поставщику 'a' заведомо больше
     * одной недостающей единицы (несколько десятков предложений по
     * random_int(1, 5) штук), поэтому restockCap: 1 гарантированно меньше
     * запрошенного независимо от точных чисел сида.
     *
     * Фикс-раунд 1, Minor 3: ожидание — конкретная пара чисел, а не только
     * префикс сообщения. Префикс один проходит и при перепутанных
     * аргументах sprintf («запрошено 1, добавлено N» вместо «запрошено N,
     * добавлено 1») — ровно ту ошибку, которую тест и должен ловить.
     * $missing считаем тем же выражением, что и команда (STOCK_BUFFER = 1.1
     * приватна в SyncSupplierStock — дублируем множитель, а не меняем
     * видимость константы ради теста); FakeSupplier::inventory() всегда
     * отдаёт total=0, поэтому missing = target целиком.
     */
    public function test_warns_when_a_supplier_restocks_fewer_keys_than_requested(): void
    {
        $short = new FakeSupplier('a', [], restockCap: 1);
        $full = new FakeSupplier('b', []);

        $this->app->instance(SupplierRegistry::class, new SupplierRegistry(['a' => $short, 'b' => $full]));

        $units = (int) DB::table('stock_units')
            ->join('offers', 'offers.id', '=', 'stock_units.offer_id')
            ->where('offers.supplier_id', 'a')
            ->count();
        $missing = (int) ceil($units * 1.1);

        $this->artisan('stock:sync-suppliers')
            ->expectsOutputToContain("Поставщик a: запрошено {$missing}, добавлено 1 — расхождение.")
            ->assertSuccessful();
    }

    /**
     * Симметричная проверка: когда поставщик добавляет ровно столько,
     * сколько запросили, предупреждения быть не должно — иначе сообщение
     * потеряло бы всякий сигнал.
     */
    public function test_does_not_warn_when_the_supplier_covers_the_full_request(): void
    {
        $full = new FakeSupplier('a', []);

        $this->app->instance(SupplierRegistry::class, new SupplierRegistry(['a' => $full]));

        $this->artisan('stock:sync-suppliers')
            ->doesntExpectOutputToContain('расхождение')
            ->assertSuccessful();
    }
}
