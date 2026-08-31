<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

/** Каталог из приложения к ТЗ. Цены переведены в копейки. */
final class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['STEAM-TOPUP-500', 'Пополнение Steam 500 ₽', 'topup', 500, 'assets/steam.png'],
            ['STEAM-TOPUP-1000', 'Пополнение Steam 1000 ₽', 'topup', 1000, 'assets/steam.png'],
            ['STEAM-TOPUP-2500', 'Пополнение Steam 2500 ₽', 'topup', 2500, 'assets/steam.png'],
            ['KEY-CS2-PRIME', 'CS2 Prime Status ключ', 'key', 1290, 'assets/cs2.png'],
            ['KEY-GTA5', 'GTA V ключ активации', 'key', 1990, 'assets/gta5.png'],
            ['KEY-EFT', 'Escape from Tarkov ключ', 'key', 3490, 'assets/eft.png'],
            ['SUB-DISCORD-1M', 'Discord Nitro 1 месяц', 'subscription', 399, 'assets/discord.png'],
            ['SUB-YT-3M', 'YouTube Premium 3 месяца', 'subscription', 1490, 'assets/youtube.png'],
            ['SUB-SPOTIFY-1M', 'Spotify Premium 1 месяц', 'subscription', 299, 'assets/spotify.png'],
            ['GIFT-PSN-1000', 'PlayStation Store карта 1000 ₽', 'giftcard', 1000, 'assets/psn.png'],
            ['GIFT-XBOX-1500', 'Xbox Gift Card 1500 ₽', 'giftcard', 1500, 'assets/xbox.png'],
            ['GIFT-ROBLOX-800', 'Roblox 800 Robux', 'giftcard', 890, 'assets/roblox.png'],
        ];

        foreach ($products as [$sku, $name, $type, $priceRub, $image]) {
            Product::query()->updateOrCreate(['sku' => $sku], [
                'name' => $name,
                'type' => $type,
                'price_minor' => $priceRub * 100,
                'currency' => 'RUB',
                'image' => $image,
            ]);
        }
    }
}
