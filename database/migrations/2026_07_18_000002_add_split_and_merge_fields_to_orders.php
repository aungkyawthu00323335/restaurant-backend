<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_order_id')->nullable()->after('order_no');
            $table->string('split_group_id', 50)->nullable()->after('parent_order_id');
            $table->tinyInteger('split_sequence')->unsigned()->nullable()->after('split_group_id');
            $table->unsignedBigInteger('split_from_order_id')->nullable()->after('split_sequence');
            $table->unsignedBigInteger('table_merge_group_id')->nullable()->after('split_from_order_id');
            $table->unsignedBigInteger('reservation_id')->nullable()->after('table_merge_group_id');
            $table->unsignedBigInteger('version_number')->default(1)->after('cancellation_reason');
            $table->string('payment_state', 20)->default('unpaid')->after('payment_completed_at');

            $table->foreign('parent_order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('split_from_order_id')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['parent_order_id']);
            $table->dropForeign(['split_from_order_id']);
            $table->dropColumn([
                'parent_order_id', 'split_group_id', 'split_sequence',
                'split_from_order_id', 'table_merge_group_id', 'reservation_id',
                'version_number', 'payment_state',
            ]);
        });
    }
};
