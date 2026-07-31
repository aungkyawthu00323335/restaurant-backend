<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('activity_logs', 'outlet_id')) {
                $table->foreignId('outlet_id')->nullable()->after('user_id')->constrained('locations')->nullOnDelete();
            }
            if (! Schema::hasColumn('activity_logs', 'reference_type')) {
                $table->string('reference_type')->nullable()->after('module');
            }
            if (! Schema::hasColumn('activity_logs', 'reference_id')) {
                $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');
            }
            if (! Schema::hasColumn('activity_logs', 'reason')) {
                $table->text('reason')->nullable()->after('reference_id');
            }
            if (! Schema::hasColumn('activity_logs', 'request_id')) {
                $table->string('request_id', 80)->nullable()->after('reason');
            }
        });

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->index(['outlet_id', 'created_at'], 'activity_logs_outlet_created_index');
            $table->index(['reference_type', 'reference_id'], 'activity_logs_reference_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex('activity_logs_outlet_created_index');
            $table->dropIndex('activity_logs_reference_index');
            $table->dropForeign(['outlet_id']);
            $table->dropColumn(['outlet_id', 'reference_type', 'reference_id', 'reason', 'request_id']);
        });
    }
};
