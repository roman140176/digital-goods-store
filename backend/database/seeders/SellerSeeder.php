<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Seller;
use Illuminate\Database\Seeder;

/**
 * Продавцы витрины. Рейтинг чисто декоративный (влияет только на карточку
 * предложения), поэтому диапазон 4.2–5.0 выбран без бизнес-смысла — просто
 * чтобы ни один продавец не выглядел на витрине подозрительно плохим.
 */
final class SellerSeeder extends Seeder
{
    public function run(): void
    {
        $sellers = [
            ['KeyMarket', 4.8],
            ['GameHub', 4.6],
            ['DigitalOne', 4.3],
            ['SteamBazar', 4.9],
            ['PlayCorner', 4.2],
            ['CodeVault', 5.0],
            ['FastKeys', 4.5],
            ['TopUpZone', 4.7],
        ];

        foreach ($sellers as [$name, $rating]) {
            Seller::query()->updateOrCreate(['name' => $name], ['rating' => $rating]);
        }
    }
}
