<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_units', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('offer_id');
            $table->string('state'); // available | reserved | sold

            // NULL у свободной и у проданной единицы — заказ, купивший единицу,
            // остаётся только в reserved_order_id на время брони; после продажи
            // поле стирается (см. stock_units_sold_shape), история — в orders.
            $table->string('reserved_order_id')->nullable();
            $table->timestampTz('reserved_until')->nullable();
            $table->timestampTz('sold_at')->nullable();
            $table->timestamps();

            $table->foreign('offer_id')->references('id')->on('offers');
            $table->foreign('reserved_order_id')->references('id')->on('orders');

            $table->index(['offer_id', 'state']);
        });

        DB::statement("ALTER TABLE stock_units ADD CONSTRAINT stock_units_state_allowed
                       CHECK (state IN ('available','reserved','sold'))");

        // Требование 3.4: один заказ не держит бронь на две единицы сразу.
        // Индекс частичный — NULL не участвует в UNIQUE, поэтому свободные и
        // проданные единицы друг другу не мешают.
        DB::statement('CREATE UNIQUE INDEX stock_units_one_order_per_unit
                       ON stock_units (reserved_order_id) WHERE reserved_order_id IS NOT NULL');

        // Состояние и поля брони не могут разойтись: reserved обязан нести и
        // заказ, и дедлайн одновременно, любое другое состояние — не нести.
        DB::statement("ALTER TABLE stock_units ADD CONSTRAINT stock_units_reserved_shape
                       CHECK ((state = 'reserved')
                              = (reserved_order_id IS NOT NULL AND reserved_until IS NOT NULL))");

        // Проданная единица помечена временем продажи и не держит брони —
        // отсчёт после продажи ни на что не влияет (см. 3.3, 6.4 спеки).
        DB::statement("ALTER TABLE stock_units ADD CONSTRAINT stock_units_sold_shape
                       CHECK (state <> 'sold' OR (sold_at IS NOT NULL AND reserved_until IS NULL))");

        // Планировщик снятия брони читает только просроченные reserved —
        // частичный индекс не тащит свободные и проданные строки.
        DB::statement("CREATE INDEX stock_units_expiry ON stock_units (reserved_until)
                       WHERE state = 'reserved'");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_units');
    }
};
