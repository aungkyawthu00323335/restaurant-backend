<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no')->unique();
            $table->foreignId('outlet_id')->constrained('locations')->cascadeOnDelete();
            $table->enum('order_type', ['dine_in', 'takeaway', 'delivery']);
            $table->foreignId('floor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('tables')->nullOnDelete();
            $table->integer('pax')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->timestamp('pickup_time')->nullable();
            $table->string('delivery_partner')->nullable();
            $table->text('delivery_address')->nullable();
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->text('order_note')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('item_discount_amount', 12, 2)->default(0);
            $table->string('order_discount_type')->nullable();
            $table->decimal('order_discount_value', 12, 2)->nullable();
            $table->decimal('order_discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate_snapshot', 6, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('service_charge_rate_snapshot', 6, 2)->nullable();
            $table->decimal('service_charge_amount', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('balance_amount', 12, 2)->default(0);
            $table->decimal('change_amount', 12, 2)->default(0);
            $table->enum('order_status', ['pending', 'preparing', 'ready', 'on_the_way', 'delivered', 'completed', 'cancelled'])->default('pending');
            $table->enum('print_status', ['not_printed', 'printed', 'partially_printed', 'print_failed'])->default('not_printed');
            $table->enum('stock_deduction_status', ['none', 'deducted', 'reversed'])->default('none');
            $table->timestamp('stock_deducted_at')->nullable();
            $table->timestamp('payment_completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
