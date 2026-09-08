<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Delivery\SupplierRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Пополняет склады поставщиков под объём предложений магазина.
 *
 * Учёт магазина (stock_units) и физический склад поставщика живут в разных
 * базах и не связаны внешним ключом (см. допущение 12.3 спеки: «склад
 * магазина — учёт, поставщик — источник кода»). Без этой команды объёмный
 * каталог показывал бы товар в наличии, а реальная выдача кода падала бы в
 * out_of_stock, потому что у заглушки физически не хватало бы ключей.
 *
 * Целевой запас — сумма единиц предложений поставщика плюс 10 %: столько же,
 * сколько магазин обещает витриной, плюс небольшой буфер на будущие докупки.
 */
final class SyncSupplierStock extends Command
{
    private const STOCK_BUFFER = 1.1;

    protected $signature = 'stock:sync-suppliers';

    protected $description = 'Пополняет склады поставщиков под объём единиц их предложений';

    public function handle(SupplierRegistry $suppliers): int
    {
        $unitsBySupplier = DB::table('stock_units')
            ->join('offers', 'offers.id', '=', 'stock_units.offer_id')
            ->selectRaw('offers.supplier_id as supplier_id, count(*) as units')
            ->groupBy('offers.supplier_id')
            ->pluck('units', 'supplier_id');

        foreach ($suppliers->ids() as $id) {
            $supplier = $suppliers->get($id);

            $units = (int) ($unitsBySupplier[$id] ?? 0);
            $target = (int) ceil($units * self::STOCK_BUFFER);

            $inventory = $supplier->inventory();
            $current = (int) ($inventory['total'] ?? 0);
            $missing = $target - $current;

            if ($missing <= 0) {
                $this->info(sprintf(
                    'Поставщик %s: %d ключей уже покрывает %d единиц склада, пополнение не требуется.',
                    $id,
                    $current,
                    $units,
                ));

                continue;
            }

            $result = $supplier->restock($missing);
            $total = (int) ($result['total'] ?? ($current + $missing));

            $this->info(sprintf(
                'Поставщик %s: единиц на складе магазина %d, было ключей %d, добавлено %d, стало %d.',
                $id,
                $units,
                $current,
                $missing,
                $total,
            ));
        }

        return self::SUCCESS;
    }
}
