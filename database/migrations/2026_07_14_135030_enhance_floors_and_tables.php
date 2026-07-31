<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Floors: add code, sort_order, note, created_by, updated_by, unique indexes
        if (Schema::hasTable('floors')) {
            Schema::table('floors', function (Blueprint $table) {
                if (!Schema::hasColumn('floors', 'code')) {
                    $table->string('code', 50)->after('name');
                }
                if (!Schema::hasColumn('floors', 'sort_order')) {
                    $table->integer('sort_order')->default(0)->after('description');
                }
                if (!Schema::hasColumn('floors', 'note')) {
                    $table->text('note')->nullable()->after('description');
                }
                if (!Schema::hasColumn('floors', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->after('is_active');
                }
                if (!Schema::hasColumn('floors', 'updated_by')) {
                    $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete()->after('created_by');
                }
            });

            // Add indexes safely
            $this->addIndexIfNotExists('floors', ['location_id', 'name'], 'floors_location_id_name_unique');
            $this->addIndexIfNotExists('floors', ['location_id', 'code'], 'floors_location_id_code_unique');
            $this->addIndexIfNotExists('floors', ['is_active'], 'floors_is_active_index');
            $this->addIndexIfNotExists('floors', ['sort_order'], 'floors_sort_order_index');
        }

        // Tables: add outlet_id, code, shape, sort_order, note, created_by, updated_by
        if (Schema::hasTable('tables')) {
            Schema::table('tables', function (Blueprint $table) {
                if (!Schema::hasColumn('tables', 'outlet_id')) {
                    $table->foreignId('outlet_id')->nullable()->after('id')->constrained('locations')->nullOnDelete();
                }
                if (!Schema::hasColumn('tables', 'code')) {
                    $table->string('code', 50)->nullable()->after('table_no');
                }
                if (!Schema::hasColumn('tables', 'shape')) {
                    $table->string('shape', 50)->nullable()->after('capacity');
                }
                if (!Schema::hasColumn('tables', 'sort_order')) {
                    $table->integer('sort_order')->default(0)->after('shape');
                }
                if (!Schema::hasColumn('tables', 'note')) {
                    $table->text('note')->nullable()->after('description');
                }
                if (!Schema::hasColumn('tables', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->after('is_active');
                }
                if (!Schema::hasColumn('tables', 'updated_by')) {
                    $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete()->after('created_by');
                }
            });

            $this->addIndexIfNotExists('tables', ['floor_id', 'table_no'], 'tables_floor_id_table_no_unique');
            $this->addIndexIfNotExists('tables', ['outlet_id', 'code'], 'tables_outlet_id_code_unique');
            $this->addIndexIfNotExists('tables', ['status'], 'tables_status_index');
        }
    }

    private function addIndexIfNotExists(string $table, array $columns, string $indexName): void
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        if (empty($indexes)) {
            Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
                $t->unique($columns, $indexName);
            });
        }
    }

    public function down(): void
    {
        // Structural changes are not reversed to protect data
    }
};
