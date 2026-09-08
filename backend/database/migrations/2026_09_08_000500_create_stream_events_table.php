<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Журнал реалтайм-событий витрины: каждая строка несёт полное
        // состояние объекта (не дельту), поэтому таблица не хранит ничего,
        // кроме самого события — ни FK на offers/orders, ни updated_at
        // (строка, однажды записанная, больше не меняется).
        Schema::create('stream_events', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 'catalog' | 'order:<id>'. Свой топик на заказ, а не общий с
            // каталогом, — у заказа мало подписчиков (обычно один покупатель),
            // и его не нужно засорять чужими предложениями витрины.
            $table->string('topic');

            // 'offer.updated' | 'order.updated' — набор типов растёт вместе
            // с потребителями (задачи 4-7, 13), поэтому CHECK на тип здесь
            // намеренно не заводится: в отличие от orders/offers/stock_units,
            // это журнал, а не таблица с инвариантами предметной области.
            $table->string('type');

            $table->jsonb('payload');

            // DEFAULT на уровне базы (спека 3.1: "created_at timestamptz
            // default now()"), а не только значение, которое подставляет
            // EventBus::publish. Единственный сегодняшний писатель сам
            // передаёт now() и без этого дефолта прожил бы, но запись в обход
            // шины (например, фабрикой в тесте задач 4-13) обязана получить
            // корректную дату, а не NOT NULL-ошибку или пустую колонку,
            // которая молча ломает сортировку и PruneStreamEvents.
            $table->timestampTz('created_at')->useCurrent();

            // Отдельный индекс на id не нужен — bigIncrements уже даёт
            // PRIMARY KEY, а чтение всегда идёт "id > cursor ORDER BY id".
            $table->index('created_at'); // для чистки (PruneStreamEvents)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_events');
    }
};
