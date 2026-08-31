<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

use App\Domain\Orders\OrderStatus;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Получение кода у поставщика — единственное место, где заказ получает ключ.
 *
 * Однократность держится на трёх вещах:
 *  - строка выдачи одна на заказ (UNIQUE order_id), берётся под SKIP LOCKED;
 *  - request_id детерминирован, поэтому повтор у поставщика возвращает
 *    тот же код, а не расходует второй ключ;
 *  - запись кода в заказ идёт под условием delivered_code IS NULL.
 */
final class IssueOrderCode
{
    public function __construct(
        private readonly SupplierRegistry $suppliers,
        private readonly int $attemptsPerSupplier,
        private readonly int $lockSeconds,
    ) {}

    public function __invoke(int $deliveryId): void
    {
        $delivery = $this->claim($deliveryId);

        if ($delivery === null) {
            return; // задачу уже держит другой воркер либо она завершена
        }

        try {
            $this->deliver($delivery);
        } catch (Throwable $e) {
            // Никакой сбой не должен оставить задачу навсегда «в работе»:
            // снимаем аренду и переводим заказ в восстановимое состояние,
            // из которого его подберёт реконсилятор.
            $this->releaseWithError($delivery, $e->getMessage());
            report($e);
        }
    }

    private function deliver(Delivery $delivery): void
    {
        $order = $delivery->order;

        if ($order === null) {
            return;
        }

        // Код уже привязан к заказу: повторный проход ничего не выдаёт.
        if ($order->delivered_code !== null) {
            $this->settleAlreadyDelivered($delivery, $order);

            return;
        }

        $this->markDelivering($order);

        $allOutOfStock = true;
        $failures = [];

        foreach ($this->suppliers->ordered() as $supplier) {
            $outcome = $this->askWithRetries($supplier, $delivery, $order);

            if ($outcome->isOk()) {
                $this->attach($order, $delivery, $supplier->id(), (string) $outcome->code);

                return;
            }

            // Ответа от поставщика не было. Он мог выдать код, а подтверждение
            // потеряться. Переход к резервному израсходовал бы второй ключ,
            // поэтому останавливаемся: заказ уходит в восстановимое состояние,
            // а реконсилятор добьёт ЭТОГО ЖЕ поставщика с тем же request_id.
            if ($outcome->isAmbiguous()) {
                $this->giveUp($delivery, $order, false, sprintf(
                    '%s: %s (ответа нет, резервный поставщик исключён)',
                    $supplier->id(),
                    (string) $outcome->reason,
                ));

                return;
            }

            // Ответ получен, кода нет. Поставщик идемпотентен по request_id,
            // значит код не выдавался и резервный поставщик безопасен.
            if (! $outcome->isOutOfStock()) {
                $allOutOfStock = false;
            }

            $failures[] = $supplier->id().': '.(string) $outcome->reason;
        }

        $this->giveUp($delivery, $order, $allOutOfStock, implode('; ', $failures));
    }

    /**
     * Повторяет обращение к ОДНОМУ поставщику с тем же request_id.
     * Смена поставщика внутри этой петли невозможна намеренно.
     */
    private function askWithRetries(Supplier $supplier, Delivery $delivery, Order $order): SupplierOutcome
    {
        $outcome = SupplierOutcome::errored('not_attempted');

        for ($attempt = 1; $attempt <= $this->attemptsPerSupplier; $attempt++) {
            $outcome = $supplier->issue($delivery->request_id, $order->sku, $order->id);

            if ($outcome->isOk() || $outcome->isOutOfStock()) {
                return $outcome;
            }

            if ($attempt < $this->attemptsPerSupplier) {
                usleep(200_000 * $attempt);
            }
        }

        return $outcome;
    }

    private function releaseWithError(Delivery $delivery, string $error): void
    {
        DB::transaction(function () use ($delivery, $error): void {
            DB::affectingStatement(
                'UPDATE deliveries
                    SET state = ?, last_error = ?, locked_until = NULL, updated_at = now()
                  WHERE id = ? AND state = ?',
                [DeliveryState::Failed->value, mb_substr($error, 0, 1000), $delivery->id, DeliveryState::InProgress->value],
            );

            DB::affectingStatement(
                'UPDATE orders SET status = ?, updated_at = now()
                  WHERE id = ? AND delivered_code IS NULL',
                [OrderStatus::DeliveryFailed->value, $delivery->order_id],
            );

            OrderAudit::record(
                $delivery->order_id,
                'delivery_error',
                'worker',
                null,
                OrderStatus::DeliveryFailed->value,
                ['error' => mb_substr($error, 0, 500)],
            );
        });
    }

    private function claim(int $deliveryId): ?Delivery
    {
        return DB::transaction(function () use ($deliveryId): ?Delivery {
            // SKIP LOCKED: если строку держит другой воркер, уходим сразу,
            // а не ждём и не делаем работу дважды.
            $row = DB::selectOne(
                'SELECT id, state, locked_until FROM deliveries WHERE id = ? FOR UPDATE SKIP LOCKED',
                [$deliveryId],
            );

            if ($row === null || $row->state === DeliveryState::Done->value) {
                return null;
            }

            if ($row->state === DeliveryState::InProgress->value
                && $row->locked_until !== null
                && CarbonImmutable::parse($row->locked_until)->isFuture()) {
                return null; // аренда задачи ещё не истекла
            }

            DB::affectingStatement(
                "UPDATE deliveries
                    SET state = ?, attempts = attempts + 1,
                        locked_until = now() + (? || ' seconds')::interval,
                        updated_at = now()
                  WHERE id = ?",
                [DeliveryState::InProgress->value, $this->lockSeconds, $deliveryId],
            );

            return Delivery::query()->with('order')->find($deliveryId);
        });
    }

    private function markDelivering(Order $order): void
    {
        DB::affectingStatement(
            'UPDATE orders SET status = ?, updated_at = now()
              WHERE id = ? AND status IN (?, ?, ?)',
            [
                OrderStatus::Delivering->value,
                $order->id,
                OrderStatus::Paid->value,
                OrderStatus::OutOfStock->value,
                OrderStatus::DeliveryFailed->value,
            ],
        );
    }

    private function attach(Order $order, Delivery $delivery, string $supplierId, string $code): void
    {
        DB::transaction(function () use ($order, $delivery, $supplierId, $code): void {
            $updated = DB::affectingStatement(
                'UPDATE orders
                    SET delivered_code = ?, delivered_by = ?, delivered_at = now(),
                        status = ?, updated_at = now()
                  WHERE id = ? AND delivered_code IS NULL',
                [$code, $supplierId, OrderStatus::Delivered->value, $order->id],
            );

            DB::affectingStatement(
                'UPDATE deliveries
                    SET state = ?, code = ?, supplier = ?, last_error = NULL,
                        locked_until = NULL, updated_at = now()
                  WHERE id = ?',
                [DeliveryState::Done->value, $code, $supplierId, $delivery->id],
            );

            // Ноль обновлённых заказов означает, что код уже был привязан.
            // Благодаря детерминированному request_id это тот же самый код,
            // поэтому ничего не потеряно и не задвоено.
            OrderAudit::record(
                $order->id,
                $updated > 0 ? 'code_issued' : 'code_confirmed',
                'worker',
                OrderStatus::Delivering->value,
                OrderStatus::Delivered->value,
                ['supplier' => $supplierId, 'request_id' => $delivery->request_id],
            );
        });
    }

    private function settleAlreadyDelivered(Delivery $delivery, Order $order): void
    {
        DB::affectingStatement(
            'UPDATE deliveries
                SET state = ?, code = ?, supplier = COALESCE(supplier, ?),
                    locked_until = NULL, updated_at = now()
              WHERE id = ?',
            [DeliveryState::Done->value, $order->delivered_code, $order->delivered_by, $delivery->id],
        );
    }

    private function giveUp(Delivery $delivery, Order $order, bool $allOutOfStock, ?string $lastError): void
    {
        $state = $allOutOfStock ? DeliveryState::OutOfStock : DeliveryState::Failed;
        $status = $allOutOfStock ? OrderStatus::OutOfStock : OrderStatus::DeliveryFailed;

        DB::transaction(function () use ($delivery, $order, $state, $status, $lastError): void {
            DB::affectingStatement(
                'UPDATE deliveries
                    SET state = ?, last_error = ?, locked_until = NULL, updated_at = now()
                  WHERE id = ? AND state = ?',
                [$state->value, $lastError, $delivery->id, DeliveryState::InProgress->value],
            );

            // Заказ остаётся в восстановимом состоянии: это не падение,
            // а рабочая ветка, из которой выдача продолжится после пополнения.
            DB::affectingStatement(
                'UPDATE orders SET status = ?, updated_at = now()
                  WHERE id = ? AND delivered_code IS NULL',
                [$status->value, $order->id],
            );

            OrderAudit::record(
                $order->id,
                'delivery_gave_up',
                'worker',
                OrderStatus::Delivering->value,
                $status->value,
                ['last_error' => $lastError, 'request_id' => $delivery->request_id],
            );
        });
    }
}
