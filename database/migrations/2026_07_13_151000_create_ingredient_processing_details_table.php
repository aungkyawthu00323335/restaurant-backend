<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_processing_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_processing_id')->constrained('ingredient_processings')->cascadeOnDelete();
            $table->foreignId('input_ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->string('input_ingredient_name', 120);
            $table->decimal('input_qty', 12, 4);
            $table->decimal('input_qty_consumption', 12, 4)->default(0);
            $table->string('input_unit', 80)->nullable();
            $table->string('input_unit_type', 20)->default('consumption');
            $table->decimal('input_unit_cost', 14, 4)->default(0);
            $table->decimal('input_amount', 14, 4)->default(0);
            $table->timestamps();

            $table->index(['ingredient_processing_id', 'input_ingredient_id'], 'ipd_proc_ing');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_processing_details');
    }
};
