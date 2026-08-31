<?php

declare(strict_types=1);

/**
 * Склад ключей поставщика.
 *
 * Ключевое требование контракта: на повтор с тем же request_id поставщик обязан
 * вернуть тот же самый код. Значит таймаут не равен отказу — поставщик мог
 * успеть выдать код, а ответ не дошёл.
 */
final class Inventory
{
    /**
     * @return array{status: 'ok', code: string, reused: bool}|array{status: 'error', reason: 'out_of_stock'}
     */
    public static function issue(string $requestId, ?string $sku, ?string $orderId): array
    {
        // Быстрый путь: по этому request_id ключ уже выдавался.
        if ($code = self::findByRequest($requestId)) {
            return ['status' => 'ok', 'code' => $code, 'reused' => true];
        }

        $pdo = Db::conn();

        try {
            $pdo->beginTransaction();

            // Свободный ключ забирается под SKIP LOCKED: параллельные запросы
            // берут разные строки и не ждут друг друга.
            $stmt = $pdo->prepare(<<<'SQL'
                UPDATE keys
                   SET state = 'issued',
                       request_id = :request_id,
                       sku = :sku,
                       order_id = :order_id,
                       issued_at = now()
                 WHERE id = (
                        SELECT id FROM keys
                         WHERE state = 'free'
                         ORDER BY id
                         FOR UPDATE SKIP LOCKED
                         LIMIT 1
                       )
             RETURNING code
            SQL);

            $stmt->execute([
                ':request_id' => $requestId,
                ':sku' => $sku,
                ':order_id' => $orderId,
            ]);

            $code = $stmt->fetchColumn();

            if ($code === false) {
                $pdo->rollBack();

                return ['status' => 'error', 'reason' => 'out_of_stock'];
            }

            $pdo->commit();

            return ['status' => 'ok', 'code' => (string) $code, 'reused' => false];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // 23505 = unique_violation по keys.request_id: параллельный запрос с
            // тем же request_id успел закоммитить свой ключ. Отдаём его код —
            // ровно один ключ на один request_id, без задвоения.
            if ($e->getCode() === '23505' && ($code = self::findByRequest($requestId))) {
                return ['status' => 'ok', 'code' => $code, 'reused' => true];
            }

            throw $e;
        }
    }

    private static function findByRequest(string $requestId): ?string
    {
        $stmt = Db::conn()->prepare('SELECT code FROM keys WHERE request_id = :request_id');
        $stmt->execute([':request_id' => $requestId]);
        $code = $stmt->fetchColumn();

        return $code === false ? null : (string) $code;
    }

    /** @return array{total: int, free: int, issued: int} */
    public static function stats(): array
    {
        $row = Db::conn()->query(<<<'SQL'
            SELECT count(*)                                   AS total,
                   count(*) FILTER (WHERE state = 'free')     AS free,
                   count(*) FILTER (WHERE state = 'issued')   AS issued
              FROM keys
        SQL)->fetch();

        return [
            'total' => (int) $row['total'],
            'free' => (int) $row['free'],
            'issued' => (int) $row['issued'],
        ];
    }

    /** Убирает со склада свободные ключи — сценарий «остаток закончился». */
    public static function drain(): int
    {
        return (int) Db::conn()->exec("DELETE FROM keys WHERE state = 'free'");
    }

    /** Пополнение склада: генерирует новые ключи в формате пула из ТЗ. */
    public static function restock(int $count): int
    {
        $stmt = Db::conn()->prepare(
            "INSERT INTO keys (code, state) VALUES (:code, 'free') ON CONFLICT (code) DO NOTHING"
        );

        $added = 0;
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([':code' => self::generateCode()]);
            $added += $stmt->rowCount();
        }

        return $added;
    }

    /**
     * Засев пула из ТЗ. Отмечается флагом, а НЕ проверкой на пустоту:
     * иначе опустошённый склад пересеивался бы на следующем же запросе,
     * и один и тот же код мог уйти в два разных заказа.
     *
     * @param list<string> $codes
     */
    public static function seed(array $codes): int
    {
        if (self::flag('pool_seeded')) {
            return 0;
        }

        $stmt = Db::conn()->prepare(
            "INSERT INTO keys (code, state) VALUES (:code, 'free') ON CONFLICT (code) DO NOTHING"
        );

        $added = 0;
        foreach ($codes as $code) {
            $stmt->execute([':code' => $code]);
            $added += $stmt->rowCount();
        }

        self::setFlag('pool_seeded', '1');

        return $added;
    }

    public static function clearSeedFlag(): void
    {
        Db::conn()->exec("DELETE FROM settings WHERE key = 'pool_seeded'");
    }

    /** Какие ключи закреплены за конкретным запросом выдачи. */
    public static function issuedFor(string $requestId): array
    {
        $stmt = Db::conn()->prepare('SELECT code FROM keys WHERE request_id = :request_id ORDER BY id');
        $stmt->execute([':request_id' => $requestId]);
        $codes = array_map(static fn (array $row): string => (string) $row['code'], $stmt->fetchAll());

        return ['request_id' => $requestId, 'count' => count($codes), 'codes' => $codes];
    }

    private static function flag(string $key): bool
    {
        $stmt = Db::conn()->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute([':key' => $key]);

        return $stmt->fetchColumn() !== false;
    }

    private static function setFlag(string $key, string $value): void
    {
        $stmt = Db::conn()->prepare(
            'INSERT INTO settings (key, value) VALUES (:key, :value)
             ON CONFLICT (key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    private static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $groups = [];

        for ($g = 0; $g < 3; $g++) {
            $group = '';
            for ($i = 0; $i < 4; $i++) {
                $group .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $group;
        }

        return implode('-', $groups);
    }
}
