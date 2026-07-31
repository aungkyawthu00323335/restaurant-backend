<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('original_qty', 10, 4)->default(0)->after('qty');
            $table->decimal('active_qty', 10, 4)->default(0)->after('original_qty');
            $table->decimal('cancelled_qty', 10, 4)->default(0)->after('active_qty');
            $table->decimal('printed_qty', 10, 4)->default(0)->after('cancelled_qty');
            $table->decimal('cancelled_printed_qty', 10, 4)->default(0)->after('printed_qty');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['original_qty', 'active_qty', 'cancelled_qty', 'printed_qty', 'cancelled_printed_qty']);
        });
    }
};
