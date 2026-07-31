<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 60)->unique();
            $table->text('description')->nullable();
            $table->decimal('value', 10, 4);
            $table->string('type', 20)->default('percentage'); // percentage, fixed
            $table->date('valid_from');
            $table->date('valid_until');
            $table->decimal('min_order_amount', 10, 4)->default(0);
            $table->integer('max_usage_per_customer')->default(1);
            $table->integer('total_usage_limit')->default(0); // 0 = unlimited
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
