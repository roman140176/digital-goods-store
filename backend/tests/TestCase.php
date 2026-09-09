<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Гард против прогона тестов по базе витрины.
     *
     * compose отдаёт контейнеру backend/.env настоящими переменными окружения,
     * а они сильнее значений phpunit.xml даже с force="true": внутри контейнера
     * DB_DATABASE=store, то есть база ВИТРИНЫ. Поэтому штатное
     * `php artisan test` сносило бы данные витрины через RefreshDatabase, и
     * защита от этого жила только в цели `make test` (явные -e). Теперь она
     * живёт в тестах и работает независимо от способа запуска.
     *
     * Место выбрано не случайно: createApplication() зовётся из
     * refreshApplication() ДО setUpTraits(), а схему сносит именно
     * setUpTraits() (там подключается RefreshDatabase). Проверка в setUp()
     * после parent::setUp() опоздала бы ровно на этот вызов. Имя базы берётся
     * из КОНФИГУРАЦИИ, а не у живого соединения, — сообщение должно появляться
     * и когда базы с таким именем нет вовсе.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if (! is_string($database) || ! str_ends_with($database, '_test')) {
            throw new RuntimeException(sprintf(
                'Тесты подключены к базе «%s», а это не тестовая база: RefreshDatabase снёс бы её данные. '
                .'Запускайте `make test` — он подставляет store_test явными переменными окружения. '
                .'Точечный прогон: docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=store_test '
                .'-e QUEUE_CONNECTION=sync app php artisan test --filter=...',
                $database ?? 'null',
            ));
        }

        return $app;
    }
}
