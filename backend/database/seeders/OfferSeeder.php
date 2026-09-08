<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Offer;
use App\Models\Seller;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Предложения для 12 позиций каталога первого этапа.
 *
 * Список цен — СВОЙ, а не App\Models\Product::price_minor: колонка цены
 * переезжает с товара на предложение в задаче 4, и если сид читал бы цену
 * оттуда, при удалении колонки его пришлось бы переписывать второй раз.
 * Значения совпадают с ProductSeeder намеренно — это те же цены каталога ТЗ.
 */
final class OfferSeeder extends Seeder
{
    /** @var array<string, int> sku => базовая цена в рублях */
    private const BASE_PRICES_RUB = [
        'STEAM-TOPUP-500' => 500,
        'STEAM-TOPUP-1000' => 1000,
        'STEAM-TOPUP-2500' => 2500,
        'KEY-CS2-PRIME' => 1290,
        'KEY-GTA5' => 1990,
        'KEY-EFT' => 3490,
        'SUB-DISCORD-1M' => 399,
        'SUB-YT-3M' => 1490,
        'SUB-SPOTIFY-1M' => 299,
        'GIFT-PSN-1000' => 1000,
        'GIFT-XBOX-1500' => 1500,
        'GIFT-ROBLOX-800' => 890,
    ];

    /** Надбавки второго и третьего предложения — те же +4 %/+9 % для всех позиций. */
    private const MARKUPS = [1.0, 1.04, 1.09];

    public function run(): void
    {
        $sellers = Seller::query()->orderBy('id')->get();
        $sellerCount = $sellers->count();

        $productIndex = 0;

        foreach (self::BASE_PRICES_RUB as $sku => $baseRub) {
            // Чередование 2/3 предложений по чётности индекса — воспроизводимая
            // вариативность без RNG в самой структуре сида (единицы под
            // предложением при этом остаются случайными, см. ниже).
            $offersCount = 2 + ($productIndex % 2);

            for ($i = 0; $i < $offersCount; $i++) {
                $seller = $sellers[($productIndex + $i) % $sellerCount];
                $priceRub = (int) round($baseRub * self::MARKUPS[$i]);

                $offer = Offer::query()->firstOrNew([
                    'product_sku' => $sku,
                    'seller_id' => $seller->id,
                ]);
                $isNew = ! $offer->exists;

                $offer->fill([
                    'supplier_id' => $i % 2 === 0 ? 'a' : 'b',
                    'price_minor' => $priceRub * 100,
                    'currency' => 'RUB',
                    'status' => 'active',
                ])->save();

                // Единицы трогаем только у нового предложения: повторный
                // прогон сида (make up без --fresh) не должен стирать уже
                // забронированные или проданные единицы существующего
                // предложения — тот же принцип, что и у used_count в
                // PromocodeSeeder.
                if (! $isNew) {
                    continue;
                }

                // KEY-CS2-PRIME: самое дешёвое предложение (i=0, базовая цена
                // без надбавки) получает ровно одну единицу — на этом стоит
                // демонстрация гонки за последнюю единицу (задача 2 ТЗ).
                $units = ($sku === 'KEY-CS2-PRIME' && $i === 0) ? 1 : random_int(1, 5);

                self::insertUnits($offer->id, $units);
            }

            $productIndex++;
        }
    }

    private static function insertUnits(int $offerId, int $units): void
    {
        DB::statement(
            "INSERT INTO stock_units (offer_id, state, created_at, updated_at)
             SELECT ?, 'available', now(), now() FROM generate_series(1, ?)",
            [$offerId, $units],
        );
    }
}
