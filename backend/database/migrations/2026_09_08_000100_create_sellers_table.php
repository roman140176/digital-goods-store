<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');

            // Декоративный: влияет только на вид карточки предложения,
            // в логику выбора единицы или сортировку не участвует.
            $table->decimal('rating', 2, 1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};
