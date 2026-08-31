<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->string('id')->primary();                 // ord_<ulid>
            $table->string('sku');
            $table->integer('amount_minor');                 // цена товара
            $table->integer('discount_minor')->default(0);   // считает только сервер
            $table->integer('total_minor');                  // к оплате
            $table->string('currency', 3)->default('RUB');
            $table->string('promo_code')->nullable();
            $table->string('status');

            // Двойной клик по «Купить» приходит с одним ключом идемпотентности,
            // а UNIQUE не даёт создать второй заказ.
            $table->string('idempotency_key')->unique();

            // Метка времени применённого события платёжки: по ней решается
            // порядок, а не по времени доставки вебхука.
            $table->timestampTz('last_event_at')->nullable();
            $table->string('paid_event_id')->nullable();

            $table->string('delivered_code')->nullable();
            $table->string('delivered_by')->nullable();       // a | b
            $table->timestampTz('delivered_at')->nullable();

            $table->timestamps();

            $table->foreign('sku')->references('sku')->on('products');
            $table->index('status');
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_allowed
                       CHECK (status IN ('created','paid','delivering','delivered',
                                         'payment_failed','out_of_stock','delivery_failed'))");

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_money_sane
                       CHECK (amount_minor >= 0 AND discount_minor >= 0
                              AND total_minor >= 0 AND total_minor = amount_minor - discount_minor)');

        // Выданный код обязан быть уникальным в пределах магазина: один ключ
        // физически не может оказаться в двух заказах.
        DB::statement('CREATE UNIQUE INDEX orders_delivered_code_unique
                       ON orders (delivered_code) WHERE delivered_code IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
