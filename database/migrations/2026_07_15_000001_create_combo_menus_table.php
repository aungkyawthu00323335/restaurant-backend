<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combo_menus', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('code', 80)->unique();
            $table->foreignId('category_id')->constrained('categories');
            $table->decimal('dine_in_price', 14, 2)->default(0);
            $table->decimal('take_away_price', 14, 2)->default(0);
            $table->decimal('delivery_price', 14, 2)->default(0);
            $table->decimal('cost_per_unit', 14, 4)->default(0);
            $table->string('image_url', 2048)->nullable();
            $table->text('description')->nullable();
            $table->string('note', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combo_menus');
    }
};
