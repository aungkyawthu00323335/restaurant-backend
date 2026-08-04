<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table): void {
            if (! Schema::hasColumn('printers', 'location_id')) {
                $table->foreignId('location_id')->nullable()->after('id')->constrained('locations')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table): void {
            if (Schema::hasColumn('printers', 'location_id')) {
                $table->dropForeign(['location_id']);
                $table->dropColumn('location_id');
            }
        });
    }
};
