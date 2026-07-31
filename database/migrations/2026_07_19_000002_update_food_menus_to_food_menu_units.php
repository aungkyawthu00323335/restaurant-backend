<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Copy all existing consumption units to food_menu_units to maintain ID mapping
        DB::statement("
            INSERT INTO food_menu_units (id, name, description, is_active, created_at, updated_at, deleted_at)
            SELECT id, name, description, is_active, created_at, updated_at, deleted_at
            FROM consumption_units
        ");

        // Update food_menus table foreign key
        Schema::table('food_menus', function (Blueprint $table): void {
            $table->dropForeign('food_menus_unit_id_foreign');
            $table->foreign('unit_id')
                ->references('id')
                ->on('food_menu_units')
                ->restrictOnDelete();
        });

        // Update food_menu_productions table foreign key
        Schema::table('food_menu_productions', function (Blueprint $table): void {
            $table->dropForeign('food_menu_productions_unit_id_foreign');
            $table->foreign('unit_id')
                ->references('id')
                ->on('food_menu_units')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Revert food_menu_productions table foreign key
        Schema::table('food_menu_productions', function (Blueprint $table): void {
            $table->dropForeign('food_menu_productions_unit_id_foreign');
            $table->foreign('unit_id')
                ->references('id')
                ->on('consumption_units')
                ->restrictOnDelete();
        });

        // Revert food_menus table foreign key
        Schema::table('food_menus', function (Blueprint $table): void {
            $table->dropForeign('food_menus_unit_id_foreign');
            $table->foreign('unit_id')
                ->references('id')
                ->on('consumption_units')
                ->restrictOnDelete();
        });
    }
};
