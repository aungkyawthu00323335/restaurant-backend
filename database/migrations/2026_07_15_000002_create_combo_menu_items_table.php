<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combo_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('combo_menu_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20); // food_menu, product
            $table->unsignedBigInteger('item_id');
            $table->string('item_name_snapshot', 160);
            $table->decimal('qty', 14, 4)->default(0);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('unit_name_snapshot', 80)->nullable();
            $table->decimal('cost_per_unit_snapshot', 14, 4)->default(0);
            $table->decimal('amount', 14, 4)->default(0);
            $table->integer('sort_order')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combo_menu_items');
    }
};
