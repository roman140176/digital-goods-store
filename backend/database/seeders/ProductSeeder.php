<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Каталог из приложения к ТЗ.
 *
 * Цену здесь больше не пишем: она принадлежит предложению продавца
 * (см. OfferSeeder) — это тот же список позиций, но без пятого элемента
 * массива с рублями.
 */
final class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['STEAM-TOPUP-500', 'Пополнение Steam 500 ₽', 'topup', 'assets/steam.png'],
            ['STEAM-TOPUP-1000', 'Пополнение Steam 1000 ₽', 'topup', 'assets/steam.png'],
            ['STEAM-TOPUP-2500', 'Пополнение Steam 2500 ₽', 'topup', 'assets/steam.png'],
            ['KEY-CS2-PRIME', 'CS2 Prime Status ключ', 'key', 'assets/cs2.png'],
            ['KEY-GTA5', 'GTA V ключ активации', 'key', 'assets/gta5.png'],
            ['KEY-EFT', 'Escape from Tarkov ключ', 'key', 'assets/eft.png'],
            ['SUB-DISCORD-1M', 'Discord Nitro 1 месяц', 'subscription', 'assets/discord.png'],
            ['SUB-YT-3M', 'YouTube Premium 3 месяца', 'subscription', 'assets/youtube.png'],
            ['SUB-SPOTIFY-1M', 'Spotify Premium 1 месяц', 'subscription', 'assets/spotify.png'],
            ['GIFT-PSN-1000', 'PlayStation Store карта 1000 ₽', 'giftcard', 'assets/psn.png'],
            ['GIFT-XBOX-1500', 'Xbox Gift Card 1500 ₽', 'giftcard', 'assets/xbox.png'],
            ['GIFT-ROBLOX-800', 'Roblox 800 Robux', 'giftcard', 'assets/roblox.png'],
        ];

        foreach ($products as [$sku, $name, $type, $image]) {
            Product::query()->updateOrCreate(['sku' => $sku], [
                'name' => $name,
                'type' => $type,
                'currency' => 'RUB',
                'image' => $image,
            ]);
        }
    }
}
