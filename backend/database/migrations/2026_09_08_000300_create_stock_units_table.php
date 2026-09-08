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

            // NULL, пока единица ни разу не бронировалась либо бронь снята без
            // последующей продажи. У проданной единицы поле НЕ стирается: это
            // и история «кто купил», и ключ, по которому единица заказа
            // находится при поздней оплате (reserved_order_id = order.id, см.
            // 6.4 спеки) — именно поэтому частичный UNIQUE ниже продолжает
            // действовать и после продажи, а не только на время брони.
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
        // Индекс частичный — NULL не участвует в UNIQUE, поэтому свободные
        // единицы друг другу не мешают, а вот проданные в нём остаются
        // (reserved_order_id не стирается продажей): один заказ не выкупает
        // две единицы, даже если делает это не одновременно, а по очереди.
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

        // reserved_shape — биконъюнкция и намеренно пропускает 'available' с
        // одним из двух полей брони непустым (та же форма нужна проданной
        // единице: order_id остаётся, until пуст). Сузить reserved_shape под
        // это нельзя, поэтому свободное состояние закрывается отдельно: у
        // available оба поля обязаны быть пустыми одновременно.
        DB::statement("ALTER TABLE stock_units ADD CONSTRAINT stock_units_available_shape
                       CHECK (state <> 'available'
                              OR (reserved_order_id IS NULL AND reserved_until IS NULL))");

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
