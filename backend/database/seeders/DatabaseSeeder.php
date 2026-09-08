<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SellerSeeder::class,
            ProductSeeder::class,
            OfferSeeder::class,
            PromocodeSeeder::class,
        ]);

        // CatalogVolumeSeeder сюда намеренно не входит: пять тысяч предложений
        // не должны прогоняться на каждом RefreshDatabase в тестах. Отдельный
        // запуск — make seed-catalog (см. Makefile).
    }
}
