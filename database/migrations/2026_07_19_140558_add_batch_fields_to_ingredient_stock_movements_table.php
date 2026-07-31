<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->foreignId('ingredient_batch_id')->nullable()->constrained('ingredient_batches')->nullOnDelete();
            $table->decimal('batch_unit_cost', 12, 4)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->dropForeign(['ingredient_batch_id']);
            $table->dropColumn(['ingredient_batch_id', 'batch_unit_cost']);
        });
    }
};
