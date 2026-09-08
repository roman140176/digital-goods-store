<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('product_sku');
            $table->unsignedBigInteger('seller_id');

            // 'a' | 'b' — тот же однобуквенный код поставщика, что и в конфиге
            // первого этапа. Выдачу теперь определяет предложение, а не
            // порядок в конфиге (см. 6.5 спеки).
            $table->string('supplier_id', 1);

            $table->integer('price_minor'); // копейки: денег во float не держим
            $table->char('currency', 3)->default('RUB');
            $table->string('status'); // active | hidden
            $table->timestamps();

            $table->foreign('product_sku')->references('sku')->on('products');
            $table->foreign('seller_id')->references('id')->on('sellers');

            // Витрина и каталог всегда фильтруют по товару и активности и
            // сортируют по цене — оба варианта порядка колонок закрываются
            // одной парой составных индексов.
            $table->index(['product_sku', 'status', 'price_minor']);
            $table->index(['status', 'price_minor']);
        });

        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_price_positive
                       CHECK (price_minor > 0)');

        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_status_allowed
                       CHECK (status IN ('active','hidden'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
