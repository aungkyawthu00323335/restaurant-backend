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
        // 1. purchase_items
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropForeign(['ingredient_id']);
            $table->unsignedBigInteger('ingredient_id')->nullable()->change();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();
            
            $table->foreignId('product_id')->nullable()->after('ingredient_id')->constrained('products')->nullOnDelete();
            $table->string('unit_type', 20)->default('purchase')->after('purchase_unit_id');
        });

        // 2. ingredient_batches
        Schema::table('ingredient_batches', function (Blueprint $table) {
            $table->dropForeign(['ingredient_id']);
            $table->unsignedBigInteger('ingredient_id')->nullable()->change();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();

            $table->foreignId('product_id')->nullable()->after('ingredient_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('food_menu_id')->nullable()->after('product_id')->constrained('food_menus')->cascadeOnDelete();
        });

        // 3. ingredient_stock_movements
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->dropForeign(['ingredient_id']);
            $table->unsignedBigInteger('ingredient_id')->nullable()->change();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();

            $table->foreignId('product_id')->nullable()->after('ingredient_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('food_menu_id')->nullable()->after('product_id')->constrained('food_menus')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['food_menu_id']);
            $table->dropColumn(['product_id', 'food_menu_id']);
            
            $table->dropForeign(['ingredient_id']);
            $table->unsignedBigInteger('ingredient_id')->change();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();
        });

        Schema::table('ingredient_batches', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['food_menu_id']);
            $table->dropColumn(['product_id', 'food_menu_id']);
            
            $table->dropForeign(['ingredient_id']);
            $table->unsignedBigInteger('ingredient_id')->change();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn(['product_id', 'unit_type']);
            
            $table->dropForeign(['ingredient_id']);
            $table->unsignedBigInteger('ingredient_id')->change();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();
        });
    }
};
