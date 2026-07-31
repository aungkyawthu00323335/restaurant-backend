<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
            $table->dropForeign(['unit_id']);
            $table->dropColumn('category_id');
            $table->dropColumn('unit_id');

            $table->foreignId('product_category_id')->constrained('product_categories')->restrictOnDelete();
            $table->foreignId('product_unit_id')->constrained('product_units')->restrictOnDelete();

            $table->index('product_category_id');
            $table->index('product_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['product_category_id']);
            $table->dropForeign(['product_unit_id']);
            $table->dropColumn('product_category_id');
            $table->dropColumn('product_unit_id');

            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('consumption_units')->restrictOnDelete();

            $table->index(['category_id', 'is_active']);
            $table->index(['unit_id', 'is_active']);
        });
    }
};
