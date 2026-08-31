#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/lib.php';

/** Возвращает склады поставщиков к исходному пулу из ТЗ. */

suppliers_reset();

foreach (['a', 'b'] as $id) {
    $inventory = supplier_inventory($id);
    printf(
        "поставщик %s: всего %s, свободно %s, выдано %s\n",
        strtoupper($id),
        (string) ($inventory['total'] ?? '?'),
        (string) ($inventory['free'] ?? '?'),
        (string) ($inventory['issued'] ?? '?'),
    );
}
