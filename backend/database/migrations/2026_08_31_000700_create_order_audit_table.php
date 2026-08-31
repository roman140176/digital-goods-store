<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // История переходов: нужна и для страницы статуса, и для того чтобы
        // после состязательного прогона было видно, что произошло по факту.
        Schema::create('order_audit', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('order_id');
            $table->string('action');
            $table->string('from_state')->nullable();
            $table->string('to_state')->nullable();
            $table->string('actor');            // webhook | worker | admin | api
            $table->jsonb('meta')->nullable();
            $table->timestampTz('created_at');

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_audit');
    }
};
