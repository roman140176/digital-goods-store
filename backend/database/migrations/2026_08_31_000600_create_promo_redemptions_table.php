<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_redemptions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code');

            // Один заказ расходует промокод не более одного раза.
            $table->string('order_id')->unique();

            $table->integer('discount_minor');

            // Освобождение лимита при неуспешной оплате: не удаляем строку,
            // чтобы в аудите осталось, что код применялся и был возвращён.
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();

            $table->foreign('code')->references('code')->on('promocodes');
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_redemptions');
    }
};
