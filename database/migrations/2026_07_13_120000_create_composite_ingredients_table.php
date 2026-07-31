<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('composite_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->string('unit_type', 20)->default('consumption'); // 'purchase' or 'consumption'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composite_ingredients');
    }
};
