<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // С задачи 4a offer_id заполняет каждый новый заказ (CreateOrder
        // резолвит предложение раньше вставки строки) — ограничение лишь
        // фиксирует уже фактическое поведение на уровне базы.
        DB::statement('ALTER TABLE orders ALTER COLUMN offer_id SET NOT NULL');

        // Цена принадлежит предложению продавца, а не позиции каталога: у
        // одной позиции несколько предложений с разными ценами, и с задачи 4a
        // эту колонку никто не читает для расчёта заказа (CreateOrder берёт
        // цену из offers). Оставшиеся потребители (ProductController, сиды)
        // переводятся на цену лучшего активного предложения тем же переходом,
        // что и эта миграция.
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('price_minor');
        });
    }

    public function down(): void
    {
        // Обратный порядок операций up(): сначала колонка возвращается,
        // потом снимается NOT NULL с offer_id.
        //
        // nullable(), а не integer() как в исходной миграции создания
        // products: строки, накопленные после up(), не хранят историческую
        // цену товара — восстанавливать её здесь нечем (и не нужно: реальные
        // цены возвращает сид, а не откат миграции), а колонка без значения
        // по умолчанию не встанет NOT NULL в уже не пустую таблицу.
        Schema::table('products', function (Blueprint $table): void {
            $table->integer('price_minor')->nullable();
        });

        DB::statement('ALTER TABLE orders ALTER COLUMN offer_id DROP NOT NULL');
    }
};
