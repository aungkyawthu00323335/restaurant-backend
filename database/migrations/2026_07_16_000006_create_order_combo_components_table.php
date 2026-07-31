<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_combo_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->enum('item_type', ['food_menu', 'product']);
            $table->unsignedBigInteger('item_id');
            $table->string('item_name_snapshot');
            $table->decimal('qty_per_combo', 12, 4)->default(0);
            $table->decimal('ordered_combo_qty', 12, 4)->default(0);
            $table->decimal('total_qty', 12, 4)->default(0);
            $table->string('unit_name_snapshot')->nullable();
            $table->decimal('cost_snapshot', 12, 4)->nullable();
            $table->unsignedBigInteger('printer_id_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_combo_components');
    }
};
