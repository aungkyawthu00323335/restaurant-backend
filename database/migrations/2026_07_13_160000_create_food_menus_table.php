<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_menus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('printer_id')->constrained('printers')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('consumption_units')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('code', 80)->unique();
            $table->string('stock_deduction_method', 40)->default('no_stock');
            $table->decimal('dine_in_price', 14, 2)->default(0);
            $table->decimal('take_away_price', 14, 2)->default(0);
            $table->decimal('delivery_price', 14, 2)->default(0);
            $table->decimal('cost_per_unit', 14, 4)->default(0);
            $table->decimal('current_stock_qty', 14, 4)->default(0);
            $table->decimal('low_stock_qty', 14, 4)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->text('description')->nullable();
            $table->string('note', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'is_active']);
            $table->index(['printer_id', 'is_active']);
            $table->index(['stock_deduction_method', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_menus');
    }
};
