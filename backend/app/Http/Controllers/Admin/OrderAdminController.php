<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Delivery\DeliveryState;
use App\Domain\Delivery\SupplierRegistry;
use App\Domain\Orders\OrderStatus;
use App\Domain\Realtime\EventBus;
use App\Domain\Stock\SaleResult;
use App\Domain\Stock\StockService;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverOrder;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderAudit;
use App\Models\Promocode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Разбор заказов «оплачено, но не выдано» и безопасная повторная выдача.
 * Дизайн админки по ТЗ не требуется, нужен рабочий вид.
 */
final class OrderAdminController extends Controller
{
    public function __construct(
        private readonly SupplierRegistry $suppliers,
        private readonly StockService $stock,
        private readonly EventBus $bus,
    ) {}

    public function index(Request $request)
    {
        $onlyRefundRequired = $request->boolean('refund_required');

        $orders = Order::query()
            ->whereNull('delivered_code')
            ->whereIn('status', [
                OrderStatus::OutOfStock->value,
                OrderStatus::DeliveryFailed->value,
                OrderStatus::Delivering->value,
                OrderStatus::Paid->value,
            ])
            ->when($onlyRefundRequired, fn ($query) => $query->where('refund_required', true))
            ->with(['delivery', 'product'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        // OrderPresenter::toArray() сюда намеренно не идёт: он делает три
        // дополнительных запроса НА КАЖДЫЙ заказ (единица, предложение, курсор
        // потока) — на списке в две сотни строк это N+1. Вместо него — ровно
        // два пакетных запроса по всем offer_id и id заказов сразу, независимо
        // от того, сколько строк в списке.
        $offerIds = $orders->pluck('offer_id')->filter()->unique()->values();

        $offers = DB::table('offers')
            ->join('sellers', 'sellers.id', '=', 'offers.seller_id')
            ->whereIn('offers.id', $offerIds)
            ->select('offers.id', 'offers.price_minor', 'offers.currency', 'offers.status', 'sellers.name as seller_name')
            ->get()
            ->keyBy('id');

        // Единица заказа находится по reserved_order_id, который не стирается
        // продажей (см. 3.1 спеки) — но здесь нужна именно ЖИВАЯ бронь, а не
        // история покупки, поэтому state='reserved' в условии обязателен.
        $reservations = DB::table('stock_units')
            ->whereIn('reserved_order_id', $orders->pluck('id'))
            ->where('state', 'reserved')
            ->select('reserved_order_id', 'reserved_until')
            ->get()
            ->keyBy('reserved_order_id');

        $inventories = [];
        foreach ($this->suppliers->ordered() as $supplier) {
            $inventories[$supplier->id()] = $supplier->inventory();
        }

        return view('admin.orders', [
            'orders' => $orders,
            'offers' => $offers,
            'reservations' => $reservations,
            'inventories' => $inventories,
            'token' => (string) $request->query('token', ''),
            'onlyRefundRequired' => $onlyRefundRequired,
            'deliveredCount' => Order::query()->whereNotNull('delivered_code')->count(),
        ]);
    }

    /** Состояние лимитов промокодов — нужно и админу, и проверке параллелизма. */
    public function promocodes(): JsonResponse
    {
        return response()->json([
            'promocodes' => Promocode::query()->orderBy('code')->get()->map(fn ($promo) => [
                'code' => $promo->code,
                'type' => $promo->type,
                'value' => $promo->value,
                'currency' => $promo->currency,
                'max_uses' => $promo->max_uses,
                'used_count' => $promo->used_count,
                'remaining' => $promo->max_uses - $promo->used_count,
            ])->all(),
        ]);
    }

    /**
     * Повторная выдача. Идемпотентна по построению:
     *  - если код уже привязан, не делаем ничего;
     *  - строка выдачи одна, забирается воркером под SKIP LOCKED;
     *  - request_id тот же, поэтому поставщик вернёт тот же код.
     * Десять параллельных нажатий дают ровно один ключ.
     */
    public function redeliver(Request $request, Order $order)
    {
        if ($order->delivered_code !== null) {
            return $this->respond($request, "Заказ {$order->id} уже выдан: {$order->delivered_code}", [
                'redelivered' => false,
                'code' => $order->delivered_code,
            ]);
        }

        // «Безопасная повторная выдача» из ТЗ — это выдача ОПЛАЧЕННОГО заказа.
        // По неоплаченному кнопка не должна отдавать ключ, даже если оператор
        // подставил его id руками.
        if (! in_array($order->status, [
            OrderStatus::Paid,
            OrderStatus::Delivering,
            OrderStatus::OutOfStock,
            OrderStatus::DeliveryFailed,
        ], true)) {
            return $this->respond(
                $request,
                "Заказ {$order->id} не оплачен ({$order->status->value}): повторная выдача не запускается.",
                ['redelivered' => false, 'status' => $order->status->value],
                422,
            );
        }

        // Заказ оплачен, но единицы у него нет (out_of_stock + refund_required,
        // см. ApplyPaymentEvent::applyPaidWithoutStock) — автоматического пути
        // назад в выдачу нет (допущение 12.4 спеки), эта кнопка единственная.
        // Сначала пробуем закрыть именно эту брешь: захватить свободную
        // единицу по уже уплаченной цене. Не найдётся — честно отвечаем,
        // что выдавать пока нечего, и НЕ создаём задачу выдачи вхолостую.
        if ($order->refund_required) {
            if (! $this->reclaimUnitAfterRefund($order)) {
                return $this->respond(
                    $request,
                    "Заказ {$order->id}: оплата принята, но свободных единиц предложения по-прежнему нет — выдавать нечего.",
                    ['redelivered' => false, 'status' => $order->status->value],
                    409,
                );
            }

            // Статус и refund_required поменялись внутри транзакции —
            // перечитываем, чтобы аудит и ответ ниже отражали текущее
            // состояние, а не то, что было на входе в метод.
            $order->refresh();
        }

        $delivery = Delivery::query()->where('order_id', $order->id)->first();

        if ($delivery === null) {
            $delivery = Delivery::create([
                'order_id' => $order->id,
                'request_id' => Delivery::requestIdFor($order->id),
                'state' => DeliveryState::Pending->value,
                'attempts' => 0,
            ]);
        } else {
            // Не сбиваем аренду у воркера, который прямо сейчас в работе.
            DB::affectingStatement(
                'UPDATE deliveries
                    SET state = ?, locked_until = NULL, updated_at = now()
                  WHERE id = ?
                    AND state <> ?
                    AND (state <> ? OR locked_until IS NULL OR locked_until <= now())',
                [
                    DeliveryState::Pending->value,
                    $delivery->id,
                    DeliveryState::Done->value,
                    DeliveryState::InProgress->value,
                ],
            );
        }

        OrderAudit::record($order->id, 'manual_redeliver', 'admin', $order->status->value, null, [
            'request_id' => $delivery->request_id,
        ]);

        DeliverOrder::dispatch($delivery->id);

        return $this->respond($request, "Повторная выдача заказа {$order->id} поставлена в очередь.", [
            'redelivered' => true,
            'delivery_id' => $delivery->id,
        ]);
    }

    /**
     * Закрывает единственную дыру, которую этот этап оставляет открытой:
     * оплаченный заказ без товара (out_of_stock + refund_required). Делает
     * ровно то, что applyPaid сделал бы сам, будь единица на месте, — то
     * же sellForOrder под блокировкой заказа, тот же переход в paid.
     *
     * false, если свободных единиц по-прежнему нет: заказ не трогаем вовсе,
     * чтобы не потерять факт refund_required молча.
     */
    private function reclaimUnitAfterRefund(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            // Два одновременных нажатия: пока первый вызов держал блокировку
            // и уже снял refund_required, второй ждал на lockForUpdate. Раз
            // под блокировкой видно, что работа уже сделана, — не задваиваем
            // аудит и не публикуем события повторно ради того же самого.
            if (! $locked->refund_required) {
                return true;
            }

            $sale = $this->stock->sellForOrder($locked->id, (int) $locked->offer_id);

            if ($sale === SaleResult::NoStock) {
                return false;
            }

            $locked->update([
                'status' => OrderStatus::Paid,
                'refund_required' => false,
            ]);

            OrderAudit::record(
                $order->id,
                'refund_reclaimed',
                'admin',
                OrderStatus::OutOfStock->value,
                OrderStatus::Paid->value,
                ['sale' => $sale->name],
            );

            // Остаток предложения мог измениться перезахватом, а страница
            // заказа обязана узнать, что деньги наконец нашли товар.
            $this->bus->publishOrder($order->id);
            $this->bus->publishOffer((int) $locked->offer_id);

            return true;
        });
    }

    /** Пополнение склада поставщика — для сценария «остаток закончился». */
    public function restock(Request $request, string $supplier)
    {
        $count = max(1, (int) $request->input('count', 5));
        $result = $this->suppliers->get($supplier)->restock($count);

        return $this->respond($request, "Поставщику {$supplier} добавлено ключей: ".($result['added'] ?? 0), $result);
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
