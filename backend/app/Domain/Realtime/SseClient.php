<?php

declare(strict_types=1);

namespace App\Domain\Realtime;

/**
 * Одно SSE-подключение: сокет, буфер исходящих байт и признак «отвалился».
 *
 * Класс существует, чтобы цикл событий стримера не занимался сокетами
 * напрямую. Главное, что он обеспечивает, — НЕБЛОКИРУЮЩАЯ запись. В одном
 * процессе на все подключения блокирующий fwrite означает, что один клиент,
 * который перестал читать (свёрнутая вкладка на медленном канале, зависший
 * прокси), останавливает доставку событий всем остальным: процесс висит
 * внутри записи в его сокет. Поэтому байты складываются в буфер, а пишутся
 * ровно тогда и ровно столько, сколько принимает сокет, и цикл узнаёт о
 * готовности сокета к записи от stream_select.
 */
final class SseClient
{
    /**
     * Предел на заголовки запроса. Свой сервер обязан иметь такой предел:
     * клиент, который открыл соединение и льёт байты без пустой строки,
     * иначе растил бы буфер процесса до исчерпания памяти.
     */
    private const MAX_HEAD = 8192;

    /**
     * Предел на исходящий буфер одного подключения.
     *
     * Клиент, который не читает, — это утечка: события продолжают
     * приходить, буфер растёт. Мегабайт на подключение при лимите в 200
     * соединений ограничивает процесс сверху предсказуемо. Разрывать такое
     * подключение безопасно: журнал событий переживёт разрыв, и клиент
     * дочитает пропущенное по Last-Event-ID (4.4 спеки).
     */
    private const MAX_BUFFER = 1048576;

    /** @var resource */
    private $socket;

    private string $inbox = '';

    private string $outbox = '';

    private bool $disconnected = false;

    /** Момент последней отправки — от него считается тишина для heartbeat. */
    private float $spokeAt;

    private bool $streaming = false;

    /** @var list<string> */
    private array $topics = [];

    private ?EventCursor $cursor = null;

    /**
     * @param  resource  $socket
     */
    public function __construct($socket, private readonly string $remote, float $now)
    {
        $this->socket = $socket;
        $this->spokeAt = $now;

        stream_set_blocking($socket, false);
    }

    /**
     * @return resource
     */
    public function socket()
    {
        return $this->socket;
    }

    public function remote(): string
    {
        return $this->remote;
    }

    public function isStreaming(): bool
    {
        return $this->streaming;
    }

    /** @return list<string> */
    public function topics(): array
    {
        return $this->topics;
    }

    public function cursor(): EventCursor
    {
        // Живой режим без курсора невозможен: он и определяет, что клиенту
        // отдавать. Обращение до рукопожатия — ошибка вызывающего кода.
        if ($this->cursor === null) {
            throw new \LogicException('Курсор подключения ещё не установлен.');
        }

        return $this->cursor;
    }

    /**
     * Переводит подключение в живой режим: топики и курсор известны,
     * рукопожатие отдано.
     *
     * @param  list<string>  $topics
     */
    public function startStreaming(array $topics, EventCursor $cursor): void
    {
        $this->topics = $topics;
        $this->cursor = $cursor;
        $this->streaming = true;
        $this->inbox = '';
    }

    public function isSubscribedTo(string $topic): bool
    {
        return in_array($topic, $this->topics, true);
    }

    /**
     * Дочитывает запрос клиента и возвращает его «голову» целиком, как
     * только пришла пустая строка. null — запрос ещё не дочитан.
     *
     * Тело запроса не читается вовсе: у GET его нет, а SSE-клиент после
     * запроса больше ничего не присылает.
     */
    public function readRequestHead(): ?string
    {
        $chunk = @fread($this->socket, 4096);

        if ($chunk === false || ($chunk === '' && feof($this->socket))) {
            $this->disconnected = true;

            return null;
        }

        $this->inbox .= $chunk;

        // Одиночные \n принимаются наравне с \r\n: браузеры и curl так не
        // делают, а ручная отладка через `printf 'GET ...\n\n' | nc` — делает,
        // и спотыкаться на этом при живой проверке было бы обидно.
        foreach (["\r\n\r\n", "\n\n"] as $terminator) {
            $end = strpos($this->inbox, $terminator);

            if ($end !== false) {
                return substr($this->inbox, 0, $end);
            }
        }

        if (strlen($this->inbox) > self::MAX_HEAD) {
            $this->disconnected = true;
        }

        return null;
    }

    /**
     * Выбрасывает всё, что клиент присылает после запроса.
     *
     * Читать обязательно, даже не глядя в содержимое: непрочитанные байты
     * держат сокет «готовым к чтению», и stream_select возвращался бы из-за
     * него мгновенно на каждой итерации, превращая цикл в busy loop. Плюс
     * именно здесь замечается закрытие соединения клиентом: EOF приходит
     * тем же путём, что и данные.
     */
    public function drainInput(): void
    {
        $chunk = @fread($this->socket, 4096);

        if ($chunk === false || ($chunk === '' && feof($this->socket))) {
            $this->disconnected = true;
        }
    }

    public function write(string $bytes): void
    {
        if ($this->disconnected) {
            return;
        }

        if (strlen($this->outbox) + strlen($bytes) > self::MAX_BUFFER) {
            $this->disconnected = true;

            return;
        }

        $this->outbox .= $bytes;

        // Момент последней отправки обновляется здесь, в единственной точке
        // записи в буфер: любая другая раскладка рано или поздно завела бы
        // heartbeat, который считает тишиной поток событий.
        $this->spokeAt = microtime(true);
    }

    /**
     * Кадр SSE: id — для Last-Event-ID при реконнекте, event — тип
     * события, data — payload как есть.
     *
     * data разбивается по переводам строки, потому что в SSE перевод строки
     * внутри data — это конец поля: одна строка payload с \n превратилась бы
     * в оборванное событие. JSON от PostgreSQL переводов строки внутрь не
     * кладёт (они экранируются), но protocol framing не должен зависеть от
     * этого свойства чужого форматтера.
     */
    public function send(int $id, string $event, string $data): void
    {
        $frame = 'id: '.$id."\n".'event: '.$event."\n";

        foreach (explode("\n", str_replace("\r\n", "\n", $data)) as $line) {
            $frame .= 'data: '.$line."\n";
        }

        $this->write($frame."\n");
    }

    /**
     * Комментарий SSE — строка, начинающаяся с двоеточия. Клиент её
     * игнорирует, но байты идут по соединению: именно это и нужно, чтобы
     * прокси и NAT не сочли соединение брошенным.
     */
    public function comment(string $text): void
    {
        $this->write(': '.$text."\n\n");
    }

    public function silentFor(float $now): float
    {
        return $now - $this->spokeAt;
    }

    public function wantsWrite(): bool
    {
        return $this->outbox !== '';
    }

    /**
     * Отдаёт сокету столько байт, сколько он принимает, остальное
     * оставляет в буфере до следующей готовности.
     */
    public function flush(): void
    {
        if ($this->outbox === '' || $this->disconnected) {
            return;
        }

        $written = @fwrite($this->socket, $this->outbox);

        if ($written === false) {
            $this->disconnected = true;

            return;
        }

        if ($written === 0) {
            // Ноль записанных байт при готовом к записи сокете — либо
            // переполненное окно, либо уже закрытая другой стороной труба.
            // Различает эти случаи только feof.
            if (feof($this->socket)) {
                $this->disconnected = true;
            }

            return;
        }

        $this->outbox = substr($this->outbox, $written);
    }

    public function isDisconnected(): bool
    {
        return $this->disconnected;
    }

    public function close(): void
    {
        $this->disconnected = true;

        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }
}
