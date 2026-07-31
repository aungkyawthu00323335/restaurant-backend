<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('confirmation_status', 20)->default('draft')->after('order_status');
            $table->timestamp('confirmed_at')->nullable()->after('confirmation_status');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
        });

        DB::table('orders')
            ->whereIn('print_status', ['printed', 'partially_printed', 'print_failed'])
            ->update(['confirmation_status' => 'confirmed']);

        DB::table('order_items')
            ->where('original_qty', 0)
            ->update(['original_qty' => DB::raw('qty')]);

        DB::table('order_items')
            ->where('active_qty', 0)
            ->where('cancelled_qty', 0)
            ->update(['active_qty' => DB::raw('qty')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['confirmed_by']);
            $table->dropColumn(['confirmation_status', 'confirmed_at', 'confirmed_by']);
        });
    }
};
