<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->dateTime('transferred_at')->nullable()->after('transfer_date');
        });

        Schema::table('transfer_items', function (Blueprint $table): void {
            $table->string('unit_type', 20)->default('consumption')->after('unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_items', fn (Blueprint $table) => $table->dropColumn('unit_type'));
        Schema::table('transfers', fn (Blueprint $table) => $table->dropColumn('transferred_at'));
    }
};
