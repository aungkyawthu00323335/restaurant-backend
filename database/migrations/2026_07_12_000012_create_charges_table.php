<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->decimal('value', 10, 4);
            $table->string('type', 20)->default('percentage'); // percentage, fixed
            $table->string('apply_to', 20)->default('dinein'); // dinein, takeaway, delivery, other
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
