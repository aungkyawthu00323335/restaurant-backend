<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropIndexIfExists('floors', 'floors_is_active_index');
        $this->dropIndexIfExists('floors', 'floors_sort_order_index');
        $this->dropIndexIfExists('tables', 'tables_status_index');

        if (Schema::hasTable('floors')) {
            Schema::table('floors', function (Blueprint $t) {
                $t->index(['is_active'], 'floors_is_active_index');
                $t->index(['sort_order'], 'floors_sort_order_index');
            });
        }

        if (Schema::hasTable('tables')) {
            Schema::table('tables', function (Blueprint $t) {
                $t->index(['status'], 'tables_status_index');
            });
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        if (!empty($indexes)) {
            Schema::table($table, function (Blueprint $t) use ($table, $indexName) {
                $t->dropIndex($indexName);
            });
        }
    }

    public function down(): void
    {
    }
};
