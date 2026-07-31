<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['suppliers', 'customers', 'gift_cards', 'coupons', 'locations', 'currencies', 'payment_methods', 'tax_rates', 'discounts', 'charges'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->string('number', 60)->nullable()->after('id');
            });
        }
    }

    public function down(): void
    {
        $tables = ['suppliers', 'customers', 'gift_cards', 'coupons', 'locations', 'currencies', 'payment_methods', 'tax_rates', 'discounts', 'charges'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn('number');
            });
        }
    }
};
