<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Realtime\StreamRequest;
use PHPUnit\Framework\TestCase;

/**
 * Разбор запроса на подписку — контракт, на который опирается фронт, поэтому
 * он проверяется вызовом функции, а не поднятым контейнером с curl (как было
 * до ревью).
 *
 * Проверяется через публичный StreamRequest::parse(), а не через открытые
 * «для теста» приватные методы: тест обязан ломаться от изменения контракта,
 * а не от переноса кода между внутренними функциями.
 */
final class StreamRequestTest extends TestCase
{
    private function head(string $target, string ...$headers): string
    {
        return implode("\r\n", ["GET {$target} HTTP/1.1", 'Host: localhost', ...$headers]);
    }

    public function test_request_line_and_headers_are_parsed(): void
    {
        $request = StreamRequest::parse($this->head('/api/stream?topics=catalog'));

        $this->assertNotNull($request);
        $this->assertSame('GET', $request->method);

        // Путь — без query-строки: по нему команда решает, её ли это запрос.
        $this->assertSame('/api/stream', $request->path);
    }

    public function test_method_is_upper_cased_and_path_is_url_decoded(): void
    {
        $request = StreamRequest::parse("post /api/str%65am HTTP/1.1\r\nHost: localhost");

        $this->assertNotNull($request);

        // Метод сравнивается с 'GET', и регистр не должен превращать отказ
        // 405 в загадочный 404 или наоборот.
        $this->assertSame('POST', $request->method);
        $this->assertSame('/api/stream', $request->path);
    }

    public function test_malformed_request_line_is_rejected(): void
    {
        // Ни строки запроса, ни двух её частей — отвечать на такое нужно 400,
        // а не догадками о пути и методе.
        $this->assertNull(StreamRequest::parse(''));
        $this->assertNull(StreamRequest::parse("\r\nHost: localhost"));
        $this->assertNull(StreamRequest::parse("GARBAGE\r\nHost: localhost"));
    }

    public function test_topics_are_split_deduplicated_and_sanitized(): void
    {
        $request = StreamRequest::parse($this->head(
            '/api/stream?topics=catalog,order:ord_01ABC-x,catalog,%3Bdrop%20table,,order%3Aord_01ABC-x',
        ));

        $this->assertNotNull($request);

        // Порядок сохранён, повтор снят, мусорное имя отброшено.
        $this->assertSame(['catalog', 'order:ord_01ABC-x'], $request->topics);
    }

    public function test_missing_or_unusable_topics_fall_back_to_the_catalog(): void
    {
        foreach (['/api/stream', '/api/stream?topics=', '/api/stream?topics=%20,%3B%3B'] as $target) {
            $request = StreamRequest::parse($this->head($target));

            $this->assertNotNull($request);
            $this->assertSame(['catalog'], $request->topics, $target);
        }
    }

    public function test_the_number_of_topics_is_capped(): void
    {
        $many = implode(',', array_map(static fn (int $n): string => "order:ord_{$n}", range(1, 40)));

        $request = StreamRequest::parse($this->head("/api/stream?topics={$many}"));

        $this->assertNotNull($request);

        // Предел не декоративный: список топиков уходит в IN-список общей
        // выборки живого режима, и одно подключение не должно его раздувать.
        $this->assertCount(StreamRequest::MAX_TOPICS, $request->topics);
        $this->assertSame('order:ord_1', $request->topics[0]);
    }

    public function test_the_last_event_id_header_wins_over_the_query_parameter(): void
    {
        $request = StreamRequest::parse($this->head(
            '/api/stream?topics=catalog&last_event_id=100',
            'Last-Event-ID: 250',
        ));

        $this->assertNotNull($request);

        // При реконнекте браузер присылает заголовок сам, и он свежее того
        // значения, что когда-то попало в адрес страницы.
        $this->assertSame(250, $request->cursor);
    }

    public function test_the_header_name_is_case_insensitive(): void
    {
        $request = StreamRequest::parse($this->head('/api/stream', 'last-event-id:  777  '));

        $this->assertNotNull($request);
        $this->assertSame(777, $request->cursor);
    }

    public function test_the_query_parameter_is_used_when_there_is_no_header(): void
    {
        $request = StreamRequest::parse($this->head('/api/stream?last_event_id=42'));

        $this->assertNotNull($request);
        $this->assertSame(42, $request->cursor);
    }

    public function test_absent_or_non_numeric_cursor_means_not_specified(): void
    {
        // -1, а не 0: ноль занят и означает «отдай журнал с самого начала»,
        // тогда как «курсор не указан» должно давать поток от текущей головы
        // журнала — иначе каждое открытие потока проигрывало бы клиенту час
        // истории, которая ему не нужна.
        foreach (['/api/stream', '/api/stream?last_event_id=', '/api/stream?last_event_id=abc',
            '/api/stream?last_event_id=-5', '/api/stream?last_event_id=1.5'] as $target) {
            $request = StreamRequest::parse($this->head($target));

            $this->assertNotNull($request);
            $this->assertSame(-1, $request->cursor, $target);
        }

        $withHeader = StreamRequest::parse($this->head('/api/stream', 'Last-Event-ID:'));

        $this->assertNotNull($withHeader);
        $this->assertSame(-1, $withHeader->cursor);
    }

    public function test_zero_cursor_is_kept_as_a_request_for_the_whole_journal(): void
    {
        $request = StreamRequest::parse($this->head('/api/stream', 'Last-Event-ID: 0'));

        $this->assertNotNull($request);
        $this->assertSame(0, $request->cursor);
    }
}
