<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final readonly class HttpSupplier implements Supplier
{
    public function __construct(
        private string $id,
        private string $baseUrl,
        private float $timeout,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function issue(string $requestId, string $sku, string $orderId): SupplierOutcome
    {
        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post($this->url('/issue'), [
                    'request_id' => $requestId,
                    'sku' => $sku,
                    'order_id' => $orderId,
                ]);
        } catch (ConnectionException $e) {
            // Различаем «не смогли подключиться» и «подключились, но ответа нет».
            // В первом случае поставщик запрос не видел — переход к резервному
            // безопасен. Во втором он мог выдать код: ситуация неоднозначна.
            return $this->isTimeout($e->getMessage())
                ? SupplierOutcome::ambiguous('timeout')
                // Соединение не состоялось: запрос мог дойти и быть выполнен.
                : SupplierOutcome::ambiguous('unreachable');
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful() && ($body['status'] ?? null) === 'ok' && ! empty($body['code'])) {
            return SupplierOutcome::ok((string) $body['code']);
        }

        // Единственный однозначный ответ «повторять бессмысленно, иди к другому».
        if (($body['reason'] ?? null) === 'out_of_stock') {
            return SupplierOutcome::outOfStock();
        }

        // Ответ получен, но кода в нём нет и причина не «out_of_stock».
        // Это НЕ доказывает, что код не выдан: поставщик мог закрепить ключ
        // и упасть на ответе. Считаем исход неизвестным.
        return SupplierOutcome::ambiguous(sprintf('http %d %s', $response->status(), (string) ($body['reason'] ?? 'unknown')));
    }

    private function isTimeout(string $message): bool
    {
        foreach (['cURL error 28', 'Operation timed out', 'timed out after'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function inventory(): array
    {
        try {
            return Http::timeout($this->timeout)->acceptJson()->get($this->url('/inventory'))->json() ?? [];
        } catch (ConnectionException) {
            return ['supplier' => $this->id, 'error' => 'unreachable'];
        }
    }

    public function restock(int $count): array
    {
        try {
            return Http::timeout($this->timeout)->acceptJson()->asJson()
                ->post($this->url('/restock'), ['count' => $count])->json() ?? [];
        } catch (ConnectionException) {
            return ['supplier' => $this->id, 'error' => 'unreachable'];
        }
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
