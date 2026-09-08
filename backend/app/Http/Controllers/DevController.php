<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Orders\CreateOrder;
use App\Domain\Orders\OrderPresenter;
use App\Domain\Stock\StockService;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Служебные ручки для воспроизведения сценариев приёмки.
 * В production недоступны.
 */
final class DevController extends Controller
{
    /**
     * Создание заказа с заранее известным идентификатором.
     *
     * Нужно ровно для одной проверки — «вебхук пришёл раньше создания
     * заказа». В обычном потоке идентификатор клиенту не подконтролен:
     * его генерирует сервер.
     */
    public function createOrderWithId(Request $request, CreateOrder $createOrder): JsonResponse
    {
        abort_if(app()->environment('production'), 404);

        $data = $request->validate([
            'id' => ['required', 'string', 'max:64', 'regex:/^ord_[a-z0-9_]+$/'],
            'sku' => ['required', 'string', 'exists:products,sku'],
            'promo_code' => ['nullable', 'string', 'max:64'],
        ]);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        $result = $createOrder(
            $data['sku'],
            $data['promo_code'] ?? null,
            $idempotencyKey !== '' ? $idempotencyKey : $data['id'],
            $data['id'],
        );

        return response()->json(
            OrderPresenter::toArray($result['order']),
            $result['created'] ? 201 : 200,
        );
    }

    /**
     * Просрочивает бронь заказа прямо сейчас — не ждать TTL целиком ни в
     * тестах, ни на демонстрации (5.4 спеки). Само снятие брони по-прежнему
     * делает только ReleaseExpiredReservations на своём тике: эта ручка лишь
     * сдвигает дедлайн единицы в прошлое, ничего больше не меняя.
     */
    public function expireReservation(Order $order, StockService $stock): JsonResponse
    {
        abort_if(app()->environment('production'), 404);

        $expired = $stock->expireNow($order->id);

        // expired=false — не ошибка клиента (бронь уже снята планировщиком
        // или единица уже продана до этого вызова), поэтому код ответа
        // остаётся 200 в обоих случаях: сценарии приёмки, дёргающие эту
        // ручку в цикле, различают исход по полю, а не по статусу ответа.
        return response()->json(array_merge(
            ['expired' => $expired],
            OrderPresenter::toArray($order->refresh()),
        ));
    }
}
