<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Orders\CreateOrder;
use App\Domain\Orders\OrderPresenter;
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
}
