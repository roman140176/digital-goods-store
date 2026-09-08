<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Seller;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Объёмный каталог под задачу «мгновенный поиск» (спека, 3.4): список из
 * ~140 игр и сервисов даёт правдоподобные уникальные названия, по которым
 * живой поиск действительно ищет что-то осмысленное.
 *
 * Игры разъезжаются по платформе И региону — «Cyberpunk 2077 — Steam (EU)»
 * встречается в реальных магазинах. Сервисы/подписки — только по региону:
 * платформы (Steam/Epic/PSN/Xbox/Nintendo/Origin) им неправдоподобны
 * («Discord Nitro — Xbox» не продаётся), а тип позиции у них общий —
 * subscription, как у SUB-DISCORD-1M/SUB-YT-3M/SUB-SPOTIFY-1M базового
 * каталога.
 *
 * В DatabaseSeeder НЕ вызывается: пять тысяч предложений на каждый
 * RefreshDatabase сделали бы тесты нестерпимо медленными. Отдельная команда —
 * `make seed-catalog` (см. Makefile).
 *
 * Идемпотентен: повторный запуск сначала сносит СВОЮ ЖЕ предыдущую выборку
 * (по маркеру в sku) и отстраивает её заново, а не копит дубли — иначе второй
 * вызов `make seed-catalog` удвоил бы каталог. Целиком в одной транзакции:
 * сбой на середине (нарушение ограничения, таймаут) должен вернуть базу к
 * состоянию до пересева, а не оставить старое уже удалённым, а новое ещё не
 * вставленным.
 */
final class CatalogVolumeSeeder extends Seeder
{
    /**
     * Платформа определяет тип позиции: ПК-площадки выдаются ключом
     * активации, консольные — картой пополнения (тот же смысл, что у
     * GIFT-PSN-1000/GIFT-XBOX-1500 в базовом каталоге).
     *
     * @var array<string, array{code: string, type: string}>
     */
    private const PLATFORMS = [
        'Steam' => ['code' => 'STEAM', 'type' => 'key'],
        'Epic' => ['code' => 'EPIC', 'type' => 'key'],
        'Origin' => ['code' => 'ORIGIN', 'type' => 'key'],
        'PSN' => ['code' => 'PSN', 'type' => 'giftcard'],
        'Xbox' => ['code' => 'XBOX', 'type' => 'giftcard'],
        'Nintendo' => ['code' => 'NINTENDO', 'type' => 'giftcard'],
    ];

    /** Тег вместо платформы у сервисов/подписок — см. buildCatalog(). */
    private const SERVICE_TAG = 'SERVICE';

    /** @var array<string, string> код региона => подпись в названии */
    private const REGIONS = [
        'RU' => 'РФ и СНГ',
        'GL' => 'Global',
        'EU' => 'EU',
        'TR' => 'TR',
        'AR' => 'AR',
    ];

    /** Сколько регионов из REGIONS достаётся одной платформе одного тайтла. */
    private const REGIONS_PER_PLATFORM = 2;

    /** Надбавка 2, 3 и 4-го предложения над базовой ценой позиции. */
    private const PRICE_LADDER = [1.0, 1.04, 1.09, 1.15];

    /** @var list<string> */
    private const NAMES = [
        // Экшен/приключения
        'Grand Theft Auto: San Andreas', 'Red Dead Redemption 2', 'Assassin\'s Creed Valhalla',
        'Assassin\'s Creed Mirage', 'Far Cry 6', 'Watch Dogs Legion', 'God of War Ragnarok',
        'Marvel\'s Spider-Man 2', 'Horizon Forbidden West', 'Death Stranding',
        'Sekiro: Shadows Die Twice', 'Ghost of Tsushima', 'Uncharted 4', 'Tomb Raider',
        'Prince of Persia: The Lost Crown',

        // RPG
        'The Witcher 3', 'Cyberpunk 2077', 'Elden Ring', 'Dark Souls III',
        'Baldur\'s Gate 3', 'Divinity: Original Sin 2', 'Skyrim', 'Fallout 4', 'Fallout 76',
        'Mass Effect Legendary Edition', 'Dragon Age: Inquisition', 'Dragon\'s Dogma 2',
        'Diablo IV', 'Diablo III', 'Path of Exile',

        // Шутеры
        'Counter-Strike 2', 'Call of Duty: Modern Warfare III', 'Call of Duty: Black Ops 6',
        'Battlefield 2042', 'Escape from Tarkov', 'Rainbow Six Siege', 'Valorant',
        'Overwatch 2', 'Apex Legends', 'Destiny 2', 'Halo Infinite', 'Borderlands 3',
        'DOOM Eternal', 'Titanfall 2', 'Helldivers 2',

        // Спорт/гонки
        'FIFA 24', 'EA Sports FC 25', 'NBA 2K25', 'Madden NFL 25', 'F1 24',
        'Forza Horizon 5', 'Gran Turismo 7', 'Need for Speed Unbound', 'Rocket League',
        'WWE 2K24', 'PGA Tour', 'Tony Hawk\'s Pro Skater 1+2',

        // Стратегии/MOBA
        'Dota 2', 'League of Legends', 'StarCraft II', 'Age of Empires IV',
        'Civilization VI', 'Total War: Warhammer III', 'Hearts of Iron IV',
        'Company of Heroes 3', 'Heroes of Might and Magic V', 'XCOM 2',
        'Crusader Kings III', 'Stellaris',

        // Батл-рояль/песочница
        'Fortnite', 'PUBG: Battlegrounds', 'Rust', 'ARK: Survival Evolved',
        'Sea of Thieves', 'Valheim', 'Palworld', 'Terraria', 'Minecraft', 'Stardew Valley',

        // Хоррор
        'Resident Evil 4', 'Resident Evil Village', 'Dead by Daylight', 'Silent Hill 2',
        'Phasmophobia', 'Outlast 2', 'The Evil Within 2', 'Alan Wake 2',

        // Файтинги
        'Mortal Kombat 1', 'Street Fighter 6', 'Tekken 8', 'Guilty Gear Strive',
        'Super Smash Bros Ultimate', 'Injustice 2', 'Brawlhalla', 'For Honor',

        // MMORPG
        'World of Warcraft', 'Final Fantasy XIV', 'Lost Ark', 'Black Desert Online',
        'New World', 'Genshin Impact',

        // Симуляторы/семейные
        'The Sims 4', 'Cities: Skylines II', 'Two Point Hospital', 'Planet Zoo',
        'Farming Simulator 22', 'Euro Truck Simulator 2', 'Microsoft Flight Simulator',
        'It Takes Two', 'Human Fall Flat', 'Among Us',

        // Инди/платформеры
        'Hollow Knight', 'Hades', 'Celeste', 'Ori and the Will of the Wisps', 'Cuphead',
        'Dead Cells', 'Stray', 'A Plague Tale: Requiem', 'Little Nightmares II', 'Portal 2',

        // Свежие ААА
        'Starfield', 'Hogwarts Legacy', 'Black Myth: Wukong', 'Final Fantasy VII Rebirth',
        'Persona 5 Royal', 'Monster Hunter Wilds', 'Kingdom Come: Deliverance II',
        'Metaphor: ReFantazio', 'Like a Dragon: Infinite Wealth',
    ];

    /**
     * Сервисы/подписки — без платформы, только регион (см. докблок класса).
     *
     * @var list<string>
     */
    private const SERVICES = [
        'Discord Nitro', 'Spotify Premium', 'YouTube Premium', 'Xbox Game Pass Ultimate',
        'PlayStation Plus', 'EA Play', 'Ubisoft+', 'Adobe Creative Cloud', 'Microsoft 365',
        'NordVPN',
    ];

    public function run(): void
    {
        $startedAt = microtime(true);

        DB::transaction(function () use ($startedAt): void {
            $this->resetPreviousRun();

            [$products, $offerRows] = $this->buildCatalog();

            foreach (array_chunk($products, 500) as $chunk) {
                // price_minor сюда больше не входит: цена живёт только в
                // предложениях (offers), позиция каталога её не хранит.
                DB::table('products')->upsert(
                    $chunk,
                    ['sku'],
                    ['name', 'type', 'currency', 'image', 'updated_at'],
                );
            }

            foreach (array_chunk($offerRows, 500) as $chunk) {
                DB::table('offers')->insert($chunk);
            }

            $this->insertStockUnits();

            $this->command?->info(sprintf(
                'CatalogVolumeSeeder: %d позиций, %d предложений за %.1f с.',
                count($products),
                count($offerRows),
                microtime(true) - $startedAt,
            ));
        });
    }

    /**
     * Строит позиции и предложения одним проходом — они всё равно нужны
     * вместе (offer.product_sku ссылается на ещё не вставленный products.sku).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function buildCatalog(): array
    {
        $sellerIds = Seller::query()->orderBy('id')->pluck('id')->all();
        $sellerCount = count($sellerIds);

        $platformNames = array_keys(self::PLATFORMS);
        $platformDefs = array_values(self::PLATFORMS);
        $regionCodes = array_keys(self::REGIONS);
        $regionCount = count($regionCodes);

        $products = [];
        $offerRows = [];
        $now = now();
        $globalOfferIndex = 0;

        foreach (self::NAMES as $nameIndex => $title) {
            $slug = self::slug($title);

            foreach ($platformDefs as $platformIndex => $platform) {
                for ($k = 0; $k < self::REGIONS_PER_PLATFORM; $k++) {
                    // Регионы разъезжаются по вращающемуся окну: без RNG в
                    // структуре сида и без буквального перебора всех 30
                    // комбинаций платформа×регион на каждое название (это
                    // утроило бы объём каталога сверх целевых ~1500 позиций).
                    $regionCode = $regionCodes[($nameIndex + $platformIndex + $k) % $regionCount];

                    $sku = "KEY-{$slug}-{$platform['code']}-{$regionCode}";
                    $baseRub = random_int(199, 4990);

                    $products[] = [
                        'sku' => $sku,
                        'name' => sprintf('%s — %s (%s)', $title, $platformNames[$platformIndex], self::REGIONS[$regionCode]),
                        'type' => $platform['type'],
                        'currency' => 'RUB',
                        'image' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $offers = $this->buildOffersForPosition($sku, $baseRub, $sellerIds, $sellerCount, $globalOfferIndex, $now);
                    array_push($offerRows, ...$offers);
                    $globalOfferIndex += count($offers);
                }
            }
        }

        foreach (self::SERVICES as $title) {
            $slug = self::slug($title);

            foreach ($regionCodes as $regionCode) {
                $sku = 'KEY-'.$slug.'-'.self::SERVICE_TAG."-{$regionCode}";
                $baseRub = random_int(199, 4990);

                $products[] = [
                    'sku' => $sku,
                    'name' => sprintf('%s (%s)', $title, self::REGIONS[$regionCode]),
                    'type' => 'subscription',
                    'currency' => 'RUB',
                    'image' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $offers = $this->buildOffersForPosition($sku, $baseRub, $sellerIds, $sellerCount, $globalOfferIndex, $now);
                array_push($offerRows, ...$offers);
                $globalOfferIndex += count($offers);
            }
        }

        return [$products, $offerRows];
    }

    /**
     * Предложения одной позиции: 1–4 штуки, надбавка по лестнице цен,
     * продавец — раунд-робин по всем уже созданным предложениям каталога,
     * поставщик чередуется a/b.
     *
     * @param  list<int>  $sellerIds
     * @return list<array<string, mixed>>
     */
    private function buildOffersForPosition(
        string $sku,
        int $baseRub,
        array $sellerIds,
        int $sellerCount,
        int $offerIndexStart,
        \DateTimeInterface $now,
    ): array {
        $offersCount = random_int(1, 4);
        $rows = [];

        for ($i = 0; $i < $offersCount; $i++) {
            $rows[] = [
                'product_sku' => $sku,
                'seller_id' => $sellerIds[($offerIndexStart + $i) % $sellerCount],
                'supplier_id' => $i % 2 === 0 ? 'a' : 'b',
                'price_minor' => ((int) round($baseRub * self::PRICE_LADDER[$i])) * 100,
                'currency' => 'RUB',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * Единицы вставляются множеством одним запросом на весь каталог, а не
     * циклом по тысячам предложений.
     *
     * Случайное число единиц считается в SELECT-списке подзапроса `ou`, а не
     * прямо аргументом generate_series в LATERAL: random() там ни с чем не
     * коррелирует (не ссылается на o.id/p.sku), и планировщик Postgres вправе
     * вычислить такое выражение ОДИН раз на весь запрос и переиспользовать
     * результат для всех строк — тогда все предложения получили бы одно и то
     * же число единиц вместо честного разброса 1..5 (проверено вручную на
     * этой базе). Через подзапрос random() — обычная колонка проекции,
     * которую Postgres обязан пересчитывать на каждую строку сканирования.
     */
    private function insertStockUnits(): void
    {
        DB::statement(
            "INSERT INTO stock_units (offer_id, state, created_at, updated_at)
             SELECT ou.id, 'available', now(), now()
               FROM (
                   SELECT o.id, (1 + floor(random() * 5))::int AS units
                     FROM offers o
                     JOIN products p ON p.sku = o.product_sku
                    WHERE p.sku ~ ?
               ) AS ou
               CROSS JOIN LATERAL generate_series(1, ou.units) AS gs(n)",
            [self::skuSuffixPattern()],
        );
    }

    /**
     * Сносит offers/stock_units СВОЕЙ ЖЕ предыдущей выборки перед пересевом.
     *
     * Без сброса повторный `make seed-catalog` не пересоздавал бы позиции
     * (products.sku upsert идемпотентен сам по себе), а плодил бы новые
     * offers/stock_units поверх старых при каждом запуске. products не
     * трогаем — upsert по sku и так не даёт дублей, а изменённая вручную
     * через /admin цена базового каталога это не задевает: суффикс отбирает
     * только строки этого сидера.
     */
    private function resetPreviousRun(): void
    {
        $suffix = self::skuSuffixPattern();

        DB::statement(
            'DELETE FROM stock_units WHERE offer_id IN (
                SELECT id FROM offers WHERE product_sku IN (SELECT sku FROM products WHERE sku ~ ?)
             )',
            [$suffix],
        );

        DB::statement(
            'DELETE FROM offers WHERE product_sku IN (SELECT sku FROM products WHERE sku ~ ?)',
            [$suffix],
        );
    }

    /**
     * Regex-суффикс sku вида -STEAM-RU, -SERVICE-RU и т. п. — единственный
     * надёжный маркер «это строка объёмного каталога»: слаг названия сам
     * может содержать дефисы (Prince of Persia: The Lost Crown), поэтому
     * считать сегменты через explode('-') нельзя, а префикс KEY- также носят
     * позиции базового каталога (KEY-CS2-PRIME).
     */
    private static function skuSuffixPattern(): string
    {
        $tags = array_merge(array_column(self::PLATFORMS, 'code'), [self::SERVICE_TAG]);
        $regionCodes = array_keys(self::REGIONS);

        return '-('.implode('|', $tags).')-('.implode('|', $regionCodes).')$';
    }

    private static function slug(string $title): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $title) ?? '';

        return strtoupper(trim($slug, '-'));
    }
}
