<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Delivery\DeliveryState;
use App\Domain\Orders\OrderStatus;
use App\Domain\Delivery\SupplierRegistry;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverOrder;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Разбор заказов «оплачено, но не выдано» и безопасная повторная выдача.
 * Дизайн админки по ТЗ не требуется, нужен рабочий вид.
 */
final class OrderAdminController extends Controller
{
    public function __construct(private readonly SupplierRegistry $suppliers) {}

    public function index(Request $request)
    {
        $orders = Order::query()
            ->whereNull('delivered_code')
            ->whereIn('status', [
                OrderStatus::OutOfStock->value,
                OrderStatus::DeliveryFailed->value,
                OrderStatus::Delivering->value,
                OrderStatus::Paid->value,
            ])
            ->with(['delivery', 'product'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $inventories = [];
        foreach ($this->suppliers->ordered() as $supplier) {
            $inventories[$supplier->id()] = $supplier->inventory();
        }

        return view('admin.orders', [
            'orders' => $orders,
            'inventories' => $inventories,
            'token' => (string) $request->query('token', ''),
            'deliveredCount' => Order::query()->whereNotNull('delivered_code')->count(),
        ]);
    }

    /** Состояние лимитов промокодов — нужно и админу, и проверке параллелизма. */
    public function promocodes(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'promocodes' => \App\Models\Promocode::query()->orderBy('code')->get()->map(fn ($promo) => [
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
                "UPDATE deliveries
                    SET state = ?, locked_until = NULL, updated_at = now()
                  WHERE id = ?
                    AND state <> ?
                    AND (state <> ? OR locked_until IS NULL OR locked_until <= now())",
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

    /** Пополнение склада поставщика — для сценария «остаток закончился». */
    public function restock(Request $request, string $supplier)
    {
        $count = max(1, (int) $request->input('count', 5));
        $result = $this->suppliers->get($supplier)->restock($count);

        return $this->respond($request, "Поставщику {$supplier} добавлено ключей: ".($result['added'] ?? 0), $result);
    }

    /** @param array<string, mixed> $payload */
    private function respond(Request $request, string $message, array $payload)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message] + $payload);
        }

        return back()->with('status', $message);
    }
}
