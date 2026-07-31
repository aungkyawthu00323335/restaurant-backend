<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('code', 80)->unique();
            $table->string('barcode', 80)->nullable()->unique();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('consumption_units')->restrictOnDelete();
            $table->decimal('purchase_price_per_unit', 14, 2)->default(0);
            $table->decimal('sell_price_per_unit', 14, 2)->default(0);
            $table->decimal('low_stock_qty', 14, 4)->default(0);
            $table->string('image_url', 2048)->nullable();
            $table->text('description')->nullable();
            $table->string('note', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('created_by_name', 120)->nullable();
            $table->string('updated_by_name', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'is_active']);
            $table->index(['unit_id', 'is_active']);
        });

        Schema::create('product_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('direction', 10); // in / out
            $table->string('reason_code', 40);
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('reference', 80)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('occurred_at');
            $table->string('created_by_name', 120)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'location_id']);
            $table->index(['location_id', 'reason_code']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_movements');
        Schema::dropIfExists('products');
    }
};
