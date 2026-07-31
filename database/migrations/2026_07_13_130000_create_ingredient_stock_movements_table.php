<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('direction', 10);
            $table->string('reason_code', 30)->nullable();
            $table->string('unit_type', 20)->default('consumption');
            $table->decimal('quantity_input', 12, 4)->default(0);
            $table->decimal('quantity_consumption', 12, 4)->default(0);
            $table->string('reference', 80)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['location_id', 'occurred_at'], 'ism_loc_occ');
            $table->index(['ingredient_id', 'location_id', 'occurred_at'], 'ism_ing_loc_occ');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_stock_movements');
    }
};
