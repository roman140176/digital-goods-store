<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

/**
 * Классификация ответа поставщика. Здесь живёт вся тонкость задачи.
 *
 * Поставщик идемпотентен по request_id: если код уже выдан, повтор вернёт
 * его же. Из этого следует важное различие:
 *
 *  - errored   — ОТВЕТ ПОЛУЧЕН, но кода в нём нет. Значит кода не существует:
 *                если бы он был выдан, идемпотентный поставщик его бы вернул.
 *                Уходить к резервному поставщику безопасно.
 *  - ambiguous — ОТВЕТА НЕ БЫЛО (таймаут). Поставщик мог выдать код, а ответ
 *                потеряться. Уходить к резервному НЕЛЬЗЯ: это израсходовало бы
 *                второй ключ. Нужно повторять этому же поставщику с тем же
 *                request_id, пока он не ответит хоть что-нибудь.
 */
final readonly class SupplierOutcome
{
    private function __construct(
        public string $kind,
        public ?string $code = null,
        public ?string $reason = null,
    ) {}

    public static function ok(string $code): self
    {
        return new self('ok', code: $code);
    }

    /** Однозначно: у этого поставщика ключей нет. */
    public static function outOfStock(): self
    {
        return new self('out_of_stock', reason: 'out_of_stock');
    }

    /** Ответ получен, кода нет — значит он и не выдавался. */
    public static function errored(string $reason): self
    {
        return new self('errored', reason: $reason);
    }

    /** Ответа не было: код мог быть выдан. Резервный поставщик исключён. */
    public static function ambiguous(string $reason): self
    {
        return new self('ambiguous', reason: $reason);
    }

    public function isOk(): bool
    {
        return $this->kind === 'ok';
    }

    public function isOutOfStock(): bool
    {
        return $this->kind === 'out_of_stock';
    }

    public function isErrored(): bool
    {
        return $this->kind === 'errored';
    }

    public function isAmbiguous(): bool
    {
        return $this->kind === 'ambiguous';
    }
}
