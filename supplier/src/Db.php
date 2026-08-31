<?php

declare(strict_types=1);

/**
 * Подключение к складу поставщика и его схема.
 *
 * Склад живёт в отдельной базе: основное приложение не имеет к нему доступа
 * и обязано ходить только через HTTP-контракт /issue.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            getenv('PGHOST') ?: 'db',
            getenv('PGPORT') ?: '5432',
            getenv('PGDATABASE') ?: 'supplier_a',
        );

        self::$pdo = new PDO($dsn, getenv('PGUSER') ?: 'app', getenv('PGPASSWORD') ?: 'secret', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$pdo;
    }

    /**
     * Одна таблица ключей несёт всю гарантию однократности:
     *  - code UNIQUE       — ключ существует в единственном экземпляре;
     *  - request_id UNIQUE — один запрос выдачи не может занять два ключа,
     *                        а два одновременных запроса с одним request_id
     *                        не могут занять разные ключи (второй падает на
     *                        constraint и перечитывает уже выданный код).
     */
    public static function ensureSchema(): void
    {
        self::conn()->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS keys (
                id         bigserial PRIMARY KEY,
                code       text NOT NULL UNIQUE,
                state      text NOT NULL DEFAULT 'free',
                request_id text UNIQUE,
                sku        text,
                order_id   text,
                issued_at  timestamptz
            );

            CREATE INDEX IF NOT EXISTS keys_free_idx ON keys (id) WHERE state = 'free';

            CREATE TABLE IF NOT EXISTS settings (
                key   text PRIMARY KEY,
                value text NOT NULL
            );
        SQL);
    }
}
