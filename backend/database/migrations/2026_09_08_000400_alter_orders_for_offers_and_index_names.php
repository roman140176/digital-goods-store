<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Nullable: CreateOrder ещё не знает о предложениях (перейдёт в
            // задаче 4), а NOT NULL уронил бы вставку любого заказа прямо
            // сейчас. Ограничение включится, когда колонка начнёт заполняться.
            $table->unsignedBigInteger('offer_id')->nullable();

            // Оплата принята, а единицы под заказом уже нет (см. 6.4 спеки) —
            // админка показывает такие заказы отдельным списком, реального
            // возврата в этом проекте нет (эквайринга тоже нет).
            $table->boolean('refund_required')->default(false);

            $table->foreign('offer_id')->references('id')->on('offers');
        });

        // reservation_expired — новая ветка жизненного цикла: бронь снята по
        // таймеру, но поздняя оплата всё ещё может перевести заказ в paid
        // (см. 6.4 спеки), поэтому статус входит в разрешённые.
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_allowed');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_allowed
                       CHECK (status IN ('created','paid','delivering','delivered',
                                         'payment_failed','out_of_stock','delivery_failed',
                                         'reservation_expired'))");

        // Поиск по подстроке и опечаткам (5 спеки): пользователь набирает
        // «cs2», «gta», «нитро» — обычный B-tree тут бесполезен.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX products_name_trgm ON products USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_name_trgm');

        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_allowed');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_allowed
                       CHECK (status IN ('created','paid','delivering','delivered',
                                         'payment_failed','out_of_stock','delivery_failed'))");

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['offer_id']);
            $table->dropColumn(['offer_id', 'refund_required']);
        });
    }
};
