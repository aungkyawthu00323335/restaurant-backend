<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_menu_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('food_menu_id')->constrained('food_menus')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('consumption_units')->restrictOnDelete();
            $table->decimal('required_qty', 14, 4);
            $table->decimal('unit_cost_snapshot', 14, 4);
            $table->decimal('amount', 14, 4);
            $table->timestamps();

            $table->unique(['food_menu_id', 'ingredient_id']);
        });

        Schema::create('food_menu_modifier_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('food_menu_id')->constrained('food_menus')->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained('modifiers')->restrictOnDelete();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('min_selection')->default(0);
            $table->unsignedInteger('max_selection')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['food_menu_id', 'modifier_group_id'], 'food_menu_modifier_group_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_menu_modifier_groups');
        Schema::dropIfExists('food_menu_ingredients');
    }
};
