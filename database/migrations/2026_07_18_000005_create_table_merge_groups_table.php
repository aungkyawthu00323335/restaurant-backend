<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_merge_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('floor_id')->nullable();
            $table->unsignedBigInteger('primary_table_id');
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('merged_by')->nullable();
            $table->timestamp('merged_at')->useCurrent();
            $table->unsignedBigInteger('unmerged_by')->nullable();
            $table->timestamp('unmerged_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->foreign('outlet_id')->references('id')->on('locations')->cascadeOnDelete();
            $table->foreign('floor_id')->references('id')->on('floors')->nullOnDelete();
            $table->foreign('primary_table_id')->references('id')->on('tables')->cascadeOnDelete();
            $table->foreign('merged_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('unmerged_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('table_merge_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merge_group_id');
            $table->unsignedBigInteger('table_id');
            $table->string('member_type', 20)->default('secondary');
            $table->string('original_status', 20)->nullable();
            $table->string('active_status', 20)->nullable();
            $table->timestamps();

            $table->foreign('merge_group_id')->references('id')->on('table_merge_groups')->cascadeOnDelete();
            $table->foreign('table_id')->references('id')->on('tables')->cascadeOnDelete();

            $table->unique(['merge_group_id', 'table_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_merge_members');
        Schema::dropIfExists('table_merge_groups');
    }
};
