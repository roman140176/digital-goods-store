<?php

declare(strict_types=1);

/**
 * Управляемые сбои поставщика. Настройки лежат в базе, а не в памяти
 * процесса: встроенный сервер PHP форкает воркеров, и настройка,
 * выставленная через POST /config, должна быть видна всем сразу.
 */
final class Chaos
{
    private const DEFAULTS = [
        'error_rate' => 'ERROR_RATE',
        'timeout_rate' => 'TIMEOUT_RATE',
        'issue_then_timeout' => 'ISSUE_THEN_TIMEOUT',
        'issue_then_error' => 'ISSUE_THEN_ERROR',
        'timeout_seconds' => 'TIMEOUT_SECONDS',
    ];

    /** @return array<string, float> */
    public static function all(): array
    {
        $stored = [];
        foreach (Db::conn()->query('SELECT key, value FROM settings') as $row) {
            $stored[$row['key']] = (float) $row['value'];
        }

        $config = [];
        foreach (self::DEFAULTS as $key => $envName) {
            $config[$key] = $stored[$key] ?? (float) (getenv($envName) ?: 0);
        }

        if ($config['timeout_seconds'] <= 0) {
            $config['timeout_seconds'] = 10.0;
        }

        return $config;
    }

    /** @param array<string, mixed> $values */
    public static function set(array $values): void
    {
        $stmt = Db::conn()->prepare(
            'INSERT INTO settings (key, value) VALUES (:k, :v)
             ON CONFLICT (key) DO UPDATE SET value = excluded.value'
        );

        foreach (array_keys(self::DEFAULTS) as $key) {
            if (array_key_exists($key, $values)) {
                $stmt->execute([':k' => $key, ':v' => (string) (float) $values[$key]]);
            }
        }
    }

    public static function rolls(float $probability): bool
    {
        return $probability > 0 && (mt_rand() / mt_getrandmax()) < $probability;
    }
}
