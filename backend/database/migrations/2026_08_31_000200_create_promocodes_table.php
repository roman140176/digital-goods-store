<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promocodes', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('type');                    // percent | amount
            $table->integer('value');                  // проценты либо копейки
            $table->string('currency', 3)->nullable(); // только для type=amount
            $table->integer('max_uses');
            $table->integer('used_count')->default(0);
            $table->timestamps();
        });

        // Лимит держится атомарным UPDATE ... WHERE used_count < max_uses,
        // а этот CHECK — второй пояс: даже кривой ручной UPDATE не переполнит счётчик.
        DB::statement('ALTER TABLE promocodes ADD CONSTRAINT promocodes_used_within_limit
                       CHECK (used_count >= 0 AND used_count <= max_uses)');
    }

    public function down(): void
    {
        Schema::dropIfExists('promocodes');
    }
};
