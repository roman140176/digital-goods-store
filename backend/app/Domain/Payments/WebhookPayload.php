<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use Carbon\CarbonImmutable;

/**
 * Тело вебхука платёжной системы по контракту из ТЗ.
 * Подпись не проверяем — ТЗ прямо освобождает от этого.
 */
final readonly class WebhookPayload
{
    public function __construct(
        public string $eventId,
        public string $orderId,
        public string $status,
        public ?int $amountMinor,
        public ?string $currency,
        public ?CarbonImmutable $createdAt,
        public array $raw,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $amount = $data['amount'] ?? null;

        return new self(
            eventId: (string) $data['event_id'],
            orderId: (string) $data['order_id'],
            status: (string) $data['status'],
            amountMinor: $amount === null ? null : (int) round(((float) $amount) * 100),
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            createdAt: isset($data['created_at']) ? CarbonImmutable::parse((string) $data['created_at']) : null,
            raw: $data,
        );
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
