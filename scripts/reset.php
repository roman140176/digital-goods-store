#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/lib.php';

/**
 * Возвращает склады поставщиков к исходному пулу из ТЗ и восстанавливает
 * демонстрационную единицу дешёвого предложения KEY-CS2-PRIME.
 *
 * Второе нужно потому, что reset.php вызывается не только после
 * migrate:fresh (make fresh, make race-all) — сид сам держит там ровно одну
 * единицу, и в этом случае вызов ниже просто ничего не меняет, — но и
 * отдельно, вручную, после того как со стендом уже кто-то поработал руками
 * (админка, race-last-unit.php несколько раз подряд). Демонстрация из брифа
 * («нажать make race-last-unit сразу после make demo-live») не должна
 * зависеть от того, что именно осталось от предыдущего сеанса.
 */

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

$hotOffer = cheapest_offer('KEY-CS2-PRIME');
admin_post('/admin/offers/'.$hotOffer['offer_id'].'/leave-one');
printf("предложение %d (KEY-CS2-PRIME, %s ₽): оставлена одна свободная единица\n", $hotOffer['offer_id'], number_format($hotOffer['price_minor'] / 100, 2, ',', ' '));
