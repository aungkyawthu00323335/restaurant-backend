<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->index(['is_active', 'sort_order'], 'categories_active_sort');
            $table->index('created_at', 'categories_created_at');
        });

        Schema::table('modifiers', function (Blueprint $table): void {
            $table->index(['is_active', 'sort_order'], 'modifiers_active_sort');
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->index(['is_active', 'is_head_office'], 'locations_active_head');
            $table->index('created_at', 'locations_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex('categories_active_sort');
            $table->dropIndex('categories_created_at');
        });

        Schema::table('modifiers', function (Blueprint $table): void {
            $table->dropIndex('modifiers_active_sort');
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->dropIndex('locations_active_head');
            $table->dropIndex('locations_created_at');
        });
    }
};
