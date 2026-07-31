<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'printer_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->foreignId('printer_id')->nullable()->after('product_category_id')->constrained('printers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'printer_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropForeign(['printer_id']);
                $table->dropColumn('printer_id');
            });
        }
    }
};
