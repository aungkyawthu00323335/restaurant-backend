<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cashier_name_snapshot');
            $table->timestamp('opened_at');
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->text('opening_note')->nullable();
            $table->decimal('cash_sale_amount', 12, 2)->default(0);
            $table->decimal('other_payment_amount', 12, 2)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_closing_balance', 12, 2)->nullable();
            $table->decimal('actual_closing_balance', 12, 2)->nullable();
            $table->decimal('difference_amount', 12, 2)->nullable();
            $table->text('closing_note')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamps();

            $table->index(['outlet_id', 'status']);
            $table->index(['cashier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
    }
};
