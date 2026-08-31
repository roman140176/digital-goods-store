<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table): void {
            // event_id первичным ключом — вся идемпотентность вебхука.
            // Повторная доставка падает на конфликт и не доходит до логики.
            $table->string('event_id')->primary();
            $table->string('order_id');
            $table->string('status');                          // paid | failed
            $table->integer('amount_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestampTz('provider_created_at')->nullable();
            $table->jsonb('payload');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->string('outcome')->nullable();

            $table->index(['order_id', 'provider_created_at']);
        });

        // Событие, пришедшее раньше создания заказа, паркуется и применяется
        // в момент создания. Частичный индекс делает разбор парковки дешёвым.
        DB::statement("CREATE INDEX payment_events_parked_idx
                       ON payment_events (order_id) WHERE outcome = 'parked_no_order'");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
