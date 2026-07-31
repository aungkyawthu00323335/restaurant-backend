<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('ingredient_category_id')->nullable()->constrained('ingredient_categories')->nullOnDelete();
            $table->foreignId('purchase_unit_id')->nullable()->constrained('purchase_units')->nullOnDelete();
            $table->foreignId('consumption_unit_id')->nullable()->constrained('consumption_units')->nullOnDelete();
            $table->decimal('conversion_rate', 12, 4)->default(0);
            $table->decimal('purchase_price', 12, 2)->default(0);
            $table->string('sku_code', 80)->nullable();
            $table->string('barcode', 80)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
