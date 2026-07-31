<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->enum('item_type', ['food_menu', 'product', 'combo']);
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name_snapshot');
            $table->string('unit_name_snapshot')->nullable();
            $table->decimal('qty', 12, 4)->default(1);
            $table->decimal('base_unit_price_snapshot', 12, 4)->default(0);
            $table->decimal('modifier_price_snapshot', 12, 4)->default(0);
            $table->decimal('final_unit_price_snapshot', 12, 4)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('cost_snapshot', 12, 4)->nullable();
            $table->text('item_note_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
