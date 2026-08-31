<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Одна выдача на заказ. Сколько бы вебхуков ни пришло параллельно,
            // строка создаётся ровно одна, остальные попытки уходят в конфликт.
            $table->string('order_id')->unique();

            // Детерминированный ключ запроса к поставщику: req_<order_id>.
            // Поставщик обязан вернуть по нему тот же код, поэтому повтор
            // после таймаута не приводит к второй выдаче.
            $table->string('request_id')->unique();

            $table->string('state');
            $table->integer('attempts')->default(0);
            $table->string('supplier')->nullable();
            $table->string('code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('locked_until')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders');
            $table->index(['state', 'locked_until']);
        });

        DB::statement("ALTER TABLE deliveries ADD CONSTRAINT deliveries_state_allowed
                       CHECK (state IN ('pending','in_progress','done','out_of_stock','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
