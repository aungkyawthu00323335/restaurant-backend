<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('price_adjustment_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
