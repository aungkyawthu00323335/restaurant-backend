<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_processings', function (Blueprint $table): void {
            $table->id();
            $table->string('ref_no', 30)->unique();
            $table->date('processing_date');
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('output_ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->string('output_ingredient_name', 120);
            $table->decimal('processing_qty', 12, 4);
            $table->string('output_unit', 80)->nullable();
            $table->decimal('total_input_cost', 14, 4)->default(0);
            $table->decimal('output_unit_cost', 14, 4)->default(0);
            $table->string('status', 20)->default('completed');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name', 120)->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reversed_by_name', 120)->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reverse_note', 500)->nullable();
            $table->timestamps();

            $table->index(['processing_date', 'status'], 'ip_proc_date_status');
            $table->index(['location_id', 'processing_date'], 'ip_loc_proc_date');
            $table->index(['output_ingredient_id', 'processing_date'], 'ip_out_ing_proc_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_processings');
    }
};
