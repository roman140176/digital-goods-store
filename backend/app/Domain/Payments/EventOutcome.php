<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/** Что система сделала с конкретным событием платёжной системы. */
enum EventOutcome: string
{
    /** Событие применено, статус заказа изменён. */
    case Applied = 'applied';

    /** Такой event_id уже приходил: ничего не делаем. */
    case Duplicate = 'duplicate';

    /** Событие старше уже применённого: пришло не по порядку. */
    case Stale = 'stale';

    /** Заказ в финальном состоянии: не трогаем. */
    case IgnoredTerminal = 'ignored_terminal';

    /** Вебхук пришёл раньше создания заказа: припаркован до его появления. */
    case ParkedNoOrder = 'parked_no_order';

    /** Сумма в событии не совпала с суммой заказа. */
    case AmountMismatch = 'amount_mismatch';

    /** Валюта события не совпала с валютой заказа. */
    case CurrencyMismatch = 'currency_mismatch';

    /** Заказ уже не в том состоянии, к которому событие применимо. */
    case Ignored = 'ignored';
}
