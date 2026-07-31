<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_change_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('action_type', 50);
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->decimal('old_qty', 10, 4)->nullable();
            $table->decimal('new_qty', 10, 4)->nullable();
            $table->decimal('changed_qty', 10, 4)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();

            $table->index('action_type');
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_change_histories');
    }
};
