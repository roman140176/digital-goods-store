<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Realtime\EventBus;
use App\Http\Controllers\Controller;
use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Управление ценой, остатком и видимостью предложения — служебные ручки
 * демонстрации живой витрины (5.4 спеки). Дизайн не требуется, важно, чтобы
 * состояние читалось однозначно и событие всегда долетало до открытых
 * вкладок.
 *
 * Каждое действие обёрнуто в DB::transaction: публикация события идёт в той
 * же транзакции, что и изменение данных (см. докблок EventBus) — иначе
 * подписчик рисковал бы получить уведомление о строке, которая ему ещё не
 * видна, либо, наоборот, не узнать об уже закоммиченном изменении, если
 * публикация случилась бы отдельным запросом после сбоя между операциями.
 */
final class OfferAdminController extends Controller
{
    public function __construct(private readonly EventBus $bus) {}

    /** Изменить цену предложения. */
    public function price(Request $request, Offer $offer)
    {
        // integer, а не (int) на голом input: "12.7" не должно тихо стать
        // 12, а "abc" — тихо стать 0, отказ обязан быть понятной 422, а не
        // молчаливым округлением. min:1 — offers_price_positive всё равно не
        // пропустит нулевую/отрицательную цену, отказываем раньше похода в
        // базу понятным полем, а не ошибкой ограничения PostgreSQL.
        $data = $request->validate([
            'price_minor' => ['required', 'integer', 'min:1'],
        ]);

        $priceMinor = (int) $data['price_minor'];

        DB::transaction(function () use ($offer, $priceMinor): void {
            $offer->update(['price_minor' => $priceMinor]);
            $this->bus->publishOffer($offer->id);
        });

        return $this->respond(
            $request,
            "Цена предложения {$offer->id} изменена на ".number_format($priceMinor / 100, 2, ',', ' ')." {$offer->currency}.",
            ['updated' => true, 'price_minor' => $priceMinor],
        );
    }

    /**
     * Выставить остаток свободных единиц. «units» — целевое число ИМЕННО
     * доступных единиц: занятые (в брони) и уже проданные не считаются и не
     * трогаются ни при добавлении, ни при уменьшении остатка.
     */
    public function stock(Request $request, Offer $offer)
    {
        // Та же причина, что и у price(): integer вместо (int) на голом
        // input отклоняет нецелый и нечисловой ввод понятной 422, а не тихо
        // округляет или обнуляет его. min:0 — тот же порог, что и у прежней
        // ручной проверки.
        $data = $request->validate([
            'units' => ['required', 'integer', 'min:0'],
        ]);

        $target = (int) $data['units'];

        DB::transaction(function () use ($offer, $target): void {
            // Блокируем ВСЕ единицы предложения, а не только available, ДО
            // подсчёта: иначе планировщик ReleaseExpiredReservations успевает
            // между подсчётом и записью перевести просроченную бронь
            // reserved → available — как раз ту строку, которую подсчёт
            // available не видел, — и итог расходится с запрошенным target
            // (см. race-reserve-vs-expire, scripts/race-reserve-vs-expire.php).
            // ORDER BY id — без него два параллельных вызова на одно
            // предложение (stock/leaveOne) брали бы блокировки в разном
            // порядке и рисковали встать в дедлок.
            DB::select(
                'SELECT id FROM stock_units WHERE offer_id = ? ORDER BY id FOR UPDATE',
                [$offer->id],
            );

            $available = (int) DB::scalar(
                "SELECT count(*) FROM stock_units WHERE offer_id = ? AND state = 'available'",
                [$offer->id],
            );

            $diff = $target - $available;

            if ($diff > 0) {
                // generate_series — один INSERT под любое число единиц вместо
                // построчных вставок (тот же приём, что и в OfferSeeder).
                DB::statement(
                    "INSERT INTO stock_units (offer_id, state, created_at, updated_at)
                     SELECT ?, 'available', now(), now() FROM generate_series(1, ?)",
                    [$offer->id, $diff],
                );
            } elseif ($diff < 0) {
                // Удаляются САМЫЕ старые свободные единицы и только они:
                // reserved и sold в этот список не попадают физически —
                // они не в WHERE state = 'available'.
                DB::statement(
                    "DELETE FROM stock_units WHERE id IN (
                        SELECT id FROM stock_units WHERE offer_id = ? AND state = 'available'
                        ORDER BY id LIMIT ?
                    )",
                    [$offer->id, -$diff],
                );
            }

            $this->bus->publishOffer($offer->id);
        });

        return $this->respond($request, "Остаток предложения {$offer->id} выставлен: {$target}.", [
            'updated' => true,
            'units' => $target,
        ]);
    }

    /**
     * Скрыть/показать предложение. Скрытие — это не «available стал 0»:
     * это исчезновение самой карточки с витрины, поэтому у него отдельный
     * тип события offer.gone, а не offer.updated (см. решения задачи 13).
     */
    public function toggle(Request $request, Offer $offer)
    {
        $goingHidden = $offer->status === 'active';
        $newStatus = $goingHidden ? 'hidden' : 'active';

        DB::transaction(function () use ($offer, $newStatus, $goingHidden): void {
            $offer->update(['status' => $newStatus]);

            if ($goingHidden) {
                $this->bus->publish('catalog', 'offer.gone', [
                    'offer_id' => $offer->id,
                    'sku' => $offer->product_sku,
                ]);
            } else {
                // Предложение снова видно — карточка должна получить полное
                // состояние сразу, а не только «offer вернулся».
                $this->bus->publishOffer($offer->id);
            }
        });

        $message = $goingHidden
            ? "Предложение {$offer->id} скрыто."
            : "Предложение {$offer->id} снова видно в каталоге.";

        return $this->respond($request, $message, ['status' => $newStatus]);
    }

    /**
     * Оставить ровно одну свободную единицу — подготовка гонки за последнюю
     * единицу одним нажатием (см. решения задачи 13): без неё воспроизвести
     * сценарий на объёмном каталоге можно только вручную выбивая единицы по
     * одной.
     */
    public function leaveOne(Request $request, Offer $offer)
    {
        DB::transaction(function () use ($offer): void {
            // Та же блокировка на ВСЕ единицы предложения, что и в stock(),
            // и по той же причине: без неё этот метод читает available тем
            // же неатомарным способом и рискует разойтись с планировщиком
            // ReleaseExpiredReservations между чтением и записью.
            DB::select(
                'SELECT id FROM stock_units WHERE offer_id = ? ORDER BY id FOR UPDATE',
                [$offer->id],
            );

            $availableIds = DB::select(
                "SELECT id FROM stock_units WHERE offer_id = ? AND state = 'available' ORDER BY id",
                [$offer->id],
            );

            if ($availableIds === []) {
                DB::statement(
                    "INSERT INTO stock_units (offer_id, state, created_at, updated_at)
                     VALUES (?, 'available', now(), now())",
                    [$offer->id],
                );
            } elseif (count($availableIds) > 1) {
                $keepId = $availableIds[0]->id;
                DB::statement(
                    "DELETE FROM stock_units WHERE offer_id = ? AND state = 'available' AND id <> ?",
                    [$offer->id, $keepId],
                );
            }

            $this->bus->publishOffer($offer->id);
        });

        return $this->respond($request, "У предложения {$offer->id} оставлена одна свободная единица.", [
            'updated' => true,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function respond(Request $request, string $message, array $payload, int $status = 200)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message] + $payload, $status);
        }

        return back()->with('status', $message);
    }
}
