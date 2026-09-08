<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Seller;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Объёмный каталог под задачу «мгновенный поиск» (спека, 3.4): список из
 * ~150 игр и сервисов × платформа × регион даёт правдоподобные уникальные
 * названия, по которым живой поиск действительно ищет что-то осмысленное.
 *
 * В DatabaseSeeder НЕ вызывается: пять тысяч предложений на каждый
 * RefreshDatabase сделали бы тесты нестерпимо медленными. Отдельная команда —
 * `make seed-catalog` (см. Makefile).
 *
 * Идемпотентен: повторный запуск сначала сносит СВОЮ ЖЕ предыдущую выборку
 * (по маркеру в sku) и отстраивает её заново, а не копит дубли — иначе второй
 * вызов `make seed-catalog` удвоил бы каталог.
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

    /**
     * Десяток узнаваемых тайтлов для демонстрации гонки за последнюю единицу
     * на объёме, а не только на базовом KEY-CS2-PRIME (спека, 3.4). У каждого
     * — ровно один Steam-вариант с единственной единицей и более дорогой
     * альтернативой; остальные платформы/регионы этих же игр — обычные
     * позиции со случайным остатком.
     *
     * @var list<string>
     */
    private const HOT_TITLES = [
        'Elden Ring', 'Baldur\'s Gate 3', 'Cyberpunk 2077', 'Hogwarts Legacy',
        'Starfield', 'Diablo IV', 'Helldivers 2', 'Red Dead Redemption 2',
        'God of War Ragnarok', 'Black Myth: Wukong', 'Alan Wake 2', 'Marvel\'s Spider-Man 2',
    ];

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

        // Сервисы/подписки
        'Discord Nitro', 'Spotify Premium', 'YouTube Premium', 'Xbox Game Pass Ultimate',
        'PlayStation Plus', 'EA Play', 'Ubisoft+', 'Adobe Creative Cloud', 'Microsoft 365',
        'NordVPN',
    ];

    public function run(): void
    {
        $startedAt = microtime(true);

        $this->resetPreviousRun();

        [$products, $offerRows, $hotSkus] = $this->buildCatalog();

        foreach (array_chunk($products, 500) as $chunk) {
            DB::table('products')->upsert(
                $chunk,
                ['sku'],
                ['name', 'type', 'price_minor', 'currency', 'image', 'updated_at'],
            );
        }

        foreach (array_chunk($offerRows, 500) as $chunk) {
            DB::table('offers')->insert($chunk);
        }

        $this->insertStockUnits($hotSkus);

        $this->command?->info(sprintf(
            'CatalogVolumeSeeder: %d позиций, %d предложений за %.1f с.',
            count($products),
            count($offerRows),
            microtime(true) - $startedAt,
        ));
    }

    /**
     * Строит позиции и предложения одним проходом по именам, платформам и
     * регионам — они всё равно нужны вместе (offer.product_sku ссылается на
     * ещё не вставленный products.sku).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<string>}
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
        $hotSkus = [];

        $now = now();
        $globalOfferIndex = 0;

        foreach (self::NAMES as $nameIndex => $title) {
            $slug = self::slug($title);
            $isHotTitle = in_array($title, self::HOT_TITLES, true);

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
                        'price_minor' => $baseRub * 100,
                        'currency' => 'RUB',
                        'image' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    // Горячей становится ровно ОДНА позиция каждого выбранного
                    // тайтла (первая платформа, первый регион окна) — иначе
                    // одна и та же игра дала бы до 12 горячих строк вместо
                    // десятка на весь каталог.
                    $isHotPosition = $isHotTitle && $platformIndex === 0 && $k === 0;
                    $offersCount = $isHotPosition ? 2 : random_int(1, 4);

                    for ($i = 0; $i < $offersCount; $i++) {
                        $offerRows[] = [
                            'product_sku' => $sku,
                            'seller_id' => $sellerIds[$globalOfferIndex % $sellerCount],
                            'supplier_id' => $i % 2 === 0 ? 'a' : 'b',
                            'price_minor' => ((int) round($baseRub * self::PRICE_LADDER[$i])) * 100,
                            'currency' => 'RUB',
                            'status' => 'active',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        $globalOfferIndex++;
                    }

                    if ($isHotPosition) {
                        $hotSkus[] = $sku;
                    }
                }
            }
        }

        return [$products, $offerRows, $hotSkus];
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
     *
     * @param  list<string>  $hotSkus
     */
    private function insertStockUnits(array $hotSkus): void
    {
        if ($hotSkus !== []) {
            $placeholders = implode(',', array_fill(0, count($hotSkus), '?'));

            // Дешёвое предложение каждой горячей позиции — ровно одна единица.
            DB::statement(
                "INSERT INTO stock_units (offer_id, state, created_at, updated_at)
                 SELECT id, 'available', now(), now() FROM (
                     SELECT DISTINCT ON (product_sku) id
                       FROM offers
                      WHERE product_sku IN ({$placeholders})
                      ORDER BY product_sku, price_minor ASC
                 ) AS hot_offer",
                $hotSkus,
            );
        }

        $sql = "INSERT INTO stock_units (offer_id, state, created_at, updated_at)
                 SELECT ou.id, 'available', now(), now()
                   FROM (
                       SELECT o.id, (1 + floor(random() * 5))::int AS units
                         FROM offers o
                         JOIN products p ON p.sku = o.product_sku
                        WHERE p.sku ~ ?";
        $bindings = [self::skuSuffixPattern()];

        if ($hotSkus !== []) {
            // Дешёвое предложение горячей позиции уже получило свою
            // единственную единицу выше — здесь его пропускаем, чтобы не
            // добавить вторую и не сломать демонстрацию гонки.
            $placeholders = implode(',', array_fill(0, count($hotSkus), '?'));
            $sql .= " AND o.id NOT IN (
                SELECT DISTINCT ON (product_sku) id FROM offers
                 WHERE product_sku IN ({$placeholders})
                 ORDER BY product_sku, price_minor ASC
            )";
            $bindings = array_merge($bindings, $hotSkus);
        }

        $sql .= '        ) AS ou
                   CROSS JOIN LATERAL generate_series(1, ou.units) AS gs(n)';

        DB::statement($sql, $bindings);
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
     * Regex-суффикс sku вида -STEAM-RU и т. п. — единственный надёжный
     * маркер «это строка объёмного каталога»: слаг названия сам может
     * содержать дефисы (Prince of Persia: The Lost Crown), поэтому считать
     * сегменты через explode('-') нельзя, а префикс KEY- также носят позиции
     * базового каталога (KEY-CS2-PRIME).
     */
    private static function skuSuffixPattern(): string
    {
        $platformCodes = array_column(self::PLATFORMS, 'code');
        $regionCodes = array_keys(self::REGIONS);

        return '-('.implode('|', $platformCodes).')-('.implode('|', $regionCodes).')$';
    }

    private static function slug(string $title): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $title) ?? '';

        return strtoupper(trim($slug, '-'));
    }
}
