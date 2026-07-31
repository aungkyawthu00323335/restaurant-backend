<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_split_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_order_id');
            $table->unsignedBigInteger('target_order_id');
            $table->string('split_group_id', 50)->nullable();
            $table->unsignedBigInteger('split_by')->nullable();
            $table->timestamp('split_at')->useCurrent();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->foreign('source_order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('target_order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('split_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('order_split_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('split_history_id');
            $table->unsignedBigInteger('source_order_item_id');
            $table->unsignedBigInteger('target_order_item_id')->nullable();
            $table->decimal('moved_qty', 10, 4);
            $table->decimal('amount', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('split_history_id')->references('id')->on('order_split_histories')->cascadeOnDelete();
            $table->foreign('source_order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
            $table->foreign('target_order_item_id')->references('id')->on('order_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_split_items');
        Schema::dropIfExists('order_split_histories');
    }
};
