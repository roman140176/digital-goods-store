<?php

return [
    /*
     * Два независимых поставщика. A основной, B резервный.
     * Таймаут короткий: заглушки умеют подвисать, а заказ не должен ждать вечно.
     */
    'suppliers' => [
        'a' => env('SUPPLIER_A_URL', 'http://supplier-a'),
        'b' => env('SUPPLIER_B_URL', 'http://supplier-b'),
    ],

    'supplier_timeout' => (float) env('SUPPLIER_TIMEOUT', 3),

    /*
     * Сколько раз повторить обращение к ОДНОМУ поставщику с тем же request_id
     * прежде чем уйти к резервному. Таймаут не равен отказу: поставщик мог
     * выдать код, а ответ потеряться, поэтому повтор идёт к тому же поставщику.
     */
    'supplier_attempts' => (int) env('SUPPLIER_ATTEMPTS', 3),

    /* На сколько задача выдачи считается взятой в работу. */
    'delivery_lock_seconds' => (int) env('DELIVERY_LOCK_SECONDS', 45),

    /* Через сколько секунд простоя реконсилятор трогает незавершённую выдачу. */
    'reconcile_after_seconds' => (int) env('DELIVERY_RECONCILE_AFTER_SECONDS', 30),

    /* Куда эмулятор платёжной системы отправляет вебхук. */
    'payment_webhook_url' => env('PAYMENT_WEBHOOK_URL', 'http://nginx/api/webhook/payment'),

    'admin_token' => env('ADMIN_TOKEN', 'admin-secret-token'),
];
