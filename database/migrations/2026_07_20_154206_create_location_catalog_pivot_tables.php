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
        Schema::create('location_food_menu', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_menu_id')->constrained()->cascadeOnDelete();
            
            $table->decimal('dine_in_price', 10, 2)->nullable();
            $table->decimal('take_away_price', 10, 2)->nullable();
            $table->decimal('delivery_price', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();

            $table->unique(['location_id', 'food_menu_id']);
        });

        Schema::create('location_combo_menu', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('combo_menu_id')->constrained()->cascadeOnDelete();
            
            $table->decimal('dine_in_price', 10, 2)->nullable();
            $table->decimal('take_away_price', 10, 2)->nullable();
            $table->decimal('delivery_price', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();

            $table->unique(['location_id', 'combo_menu_id']);
        });

        Schema::create('location_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            
            $table->decimal('sell_price_per_unit', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();

            $table->unique(['location_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_product');
        Schema::dropIfExists('location_combo_menu');
        Schema::dropIfExists('location_food_menu');
    }
};
