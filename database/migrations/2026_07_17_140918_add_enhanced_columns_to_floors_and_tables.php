<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

            $this->addIndexIfNotExists('floors', ['location_id', 'name'], 'floors_location_id_name_unique');
            $this->addIndexIfNotExists('floors', ['location_id', 'code'], 'floors_location_id_code_unique');
            $this->addPlainIndexIfNotExists('floors', ['is_active'], 'floors_is_active_index');
            $this->addPlainIndexIfNotExists('floors', ['sort_order'], 'floors_sort_order_index');
        }

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
            $this->addPlainIndexIfNotExists('tables', ['status'], 'tables_status_index');
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'sale_id')) {
                    $table->foreignId('sale_id')->nullable()->after('order_status')->constrained('sales')->nullOnDelete();
                }
                if (!Schema::hasColumn('orders', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->after('cancelled_at');
                }
            });
        }

        if (Schema::hasTable('refunds')) {
            Schema::table('refunds', function (Blueprint $table) {
                if (!Schema::hasColumn('refunds', 'order_id')) {
                    $table->foreignId('order_id')->nullable()->after('sale_id')->constrained('orders')->nullOnDelete();
                }
                if (!Schema::hasColumn('refunds', 'payment_method_id')) {
                    $table->foreignId('payment_method_id')->nullable()->after('refund_amount')->constrained('payment_methods')->nullOnDelete();
                }
            });
        }
    }

    private function addIndexIfNotExists(string $table, array $columns, string $indexName, bool $unique = true): void
    {
        if (! $this->indexExists($table, $indexName)) {
            Schema::table($table, function (Blueprint $t) use ($columns, $indexName, $unique) {
                if ($unique) {
                    $t->unique($columns, $indexName);
                } else {
                    $t->index($columns, $indexName);
                }
            });
        }
    }

    private function addPlainIndexIfNotExists(string $table, array $columns, string $indexName): void
    {
        $this->addIndexIfNotExists($table, $columns, $indexName, false);
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (DB::select("PRAGMA index_list('{$table}')") as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        return DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]) !== [];
    }

    public function down(): void
    {
    }
};
