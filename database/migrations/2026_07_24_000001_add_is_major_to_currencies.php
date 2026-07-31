<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table): void {
            $table->boolean('is_major')->default(false)->after('is_active');
        });

        // Keep existing installations usable by selecting the first active
        // currency as the initial main currency.
        $first = \DB::table('currencies')
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');
        if ($first !== null) {
            \DB::table('currencies')->where('id', $first)->update(['is_major' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table): void {
            $table->dropColumn('is_major');
        });
    }
};
