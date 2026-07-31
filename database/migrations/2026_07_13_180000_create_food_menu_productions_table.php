<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_menu_productions', function (Blueprint $table): void {
            $table->id();
            $table->string('ref_no', 40)->unique();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('food_menu_id')->constrained('food_menus')->restrictOnDelete();
            $table->date('production_date');
            $table->decimal('production_qty', 14, 4);
            $table->foreignId('unit_id')->constrained('consumption_units')->restrictOnDelete();
            $table->decimal('total_ingredient_cost', 14, 4)->default(0);
            $table->decimal('production_cost_per_unit', 14, 4)->default(0);
            $table->string('status', 20)->default('completed');
            $table->string('note', 500)->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->string('updated_by_name', 120)->nullable();
            $table->string('reversed_by_name', 120)->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reverse_note', 500)->nullable();
            $table->timestamps();

            $table->index(['location_id', 'status']);
            $table->index(['food_menu_id', 'status']);
            $table->index(['production_date', 'status']);
            $table->index('ref_no');
        });

        Schema::create('food_menu_production_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_id')->constrained('food_menu_productions')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->string('ingredient_name_snapshot', 160);
            $table->decimal('required_qty', 14, 4);
            $table->foreignId('unit_id')->constrained('consumption_units')->restrictOnDelete();
            $table->string('unit_name_snapshot', 60);
            $table->decimal('unit_cost_snapshot', 14, 4);
            $table->decimal('amount', 14, 4);
            $table->timestamps();

            $table->index(['production_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_menu_production_details');
        Schema::dropIfExists('food_menu_productions');
    }
};
