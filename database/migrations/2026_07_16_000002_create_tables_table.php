<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->string('table_no');
            $table->foreignId('floor_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('capacity')->default(1);
            $table->enum('status', ['available', 'occupied', 'reserved', 'merged', 'inactive'])->default('available');
            $table->foreignId('merged_with_table_id')->nullable()->constrained('tables')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
