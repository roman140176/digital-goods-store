<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

/**
 * Классификация ответа поставщика. Здесь живёт вся тонкость задачи.
 *
 * Уйти к резервному поставщику можно ТОЛЬКО по однозначному ответу «ключей
 * нет». Всё остальное — неизвестность:
 *
 *  - out_of_stock — семантический ответ поставщика: ключей нет, выдавать
 *                   было нечего. Кода не существует, резервный безопасен.
 *  - ambiguous    — таймаут, 5xx, недоступность, непонятная причина. Код мог
 *                   быть уже закреплён за request_id, а ответ его не донёс:
 *                   так ведёт себя и заглушка (сбой разыгрывается вокруг
 *                   выдачи, а не вместо неё). Уход к резервному израсходовал
 *                   бы ВТОРОЙ ключ, поэтому запрещён: повторяем этому же
 *                   поставщику с тем же request_id.
 *
 * Раньше «ответ получен, кода нет» считалось доказательством отсутствия кода.
 * Это неверно: 5xx может прийти и после того, как ключ закреплён.
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

    /** Исход неизвестен: код мог быть выдан. Резервный поставщик исключён. */
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

    public function isAmbiguous(): bool
    {
        return $this->kind === 'ambiguous';
    }
}
