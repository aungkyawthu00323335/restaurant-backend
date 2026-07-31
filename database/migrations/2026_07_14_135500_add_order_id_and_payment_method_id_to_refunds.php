<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('refunds')) {
            return;
        }
        Schema::table('refunds', function (Blueprint $table) {
            if (!Schema::hasColumn('refunds', 'order_id')) {
                $table->foreignId('order_id')->nullable()->after('sale_id')->constrained('orders')->nullOnDelete();
            }
            if (!Schema::hasColumn('refunds', 'payment_method_id')) {
                $table->foreignId('payment_method_id')->nullable()->after('refund_amount')->constrained('payment_methods')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('refunds')) {
            return;
        }
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn(['order_id', 'payment_method_id']);
        });
    }
};
