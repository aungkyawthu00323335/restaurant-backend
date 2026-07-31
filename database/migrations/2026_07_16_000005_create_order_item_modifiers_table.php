<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('modifier_group_id')->nullable();
            $table->string('modifier_group_name_snapshot');
            $table->unsignedBigInteger('modifier_item_id')->nullable();
            $table->string('modifier_item_name_snapshot');
            $table->decimal('price_adjustment_snapshot', 12, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_modifiers');
    }
};
