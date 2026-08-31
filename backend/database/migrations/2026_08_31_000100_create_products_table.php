<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->string('sku')->primary();
            $table->string('name');
            $table->string('type');            // topup | key | subscription | giftcard
            $table->integer('price_minor');    // копейки: денег во float не держим
            $table->string('currency', 3)->default('RUB');
            $table->string('image')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
