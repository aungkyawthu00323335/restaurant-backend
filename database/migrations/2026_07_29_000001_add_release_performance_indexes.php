<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->index(['outlet_id', 'sale_at', 'status'], 'sales_outlet_sale_status_idx');
            if (Schema::hasColumn('sales', 'cash_register_id')) {
                $table->index(['cash_register_id', 'sale_at'], 'sales_register_sale_idx');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['outlet_id', 'order_status', 'created_at'], 'orders_outlet_status_created_idx');
            $table->index(['outlet_id', 'stock_deduction_status'], 'orders_outlet_stock_status_idx');
            $table->index(['table_id', 'order_status'], 'orders_table_status_idx');
        });

        Schema::table('purchases', function (Blueprint $table): void {
            $table->index(['location_id', 'purchase_date', 'status'], 'purchases_loc_date_status_idx');
            $table->index(['supplier_id', 'purchase_date'], 'purchases_supplier_date_idx');
        });

        Schema::table('transfers', function (Blueprint $table): void {
            $table->index(['from_location_id', 'transfer_date', 'status'], 'transfers_from_date_status_idx');
            $table->index(['to_location_id', 'transfer_date', 'status'], 'transfers_to_date_status_idx');
        });

        Schema::table('transfer_items', function (Blueprint $table): void {
            $table->index(['item_type', 'item_id'], 'transfer_items_type_item_idx');
        });

        Schema::table('ingredient_batches', function (Blueprint $table): void {
            $table->index(['location_id', 'ingredient_id', 'usable_qty', 'received_at'], 'ib_loc_ing_qty_received_idx');
            if (Schema::hasColumn('ingredient_batches', 'product_id')) {
                $table->index(['location_id', 'product_id', 'usable_qty', 'received_at'], 'ib_loc_product_qty_received_idx');
            }
            if (Schema::hasColumn('ingredient_batches', 'food_menu_id')) {
                $table->index(['location_id', 'food_menu_id', 'usable_qty', 'received_at'], 'ib_loc_food_qty_received_idx');
            }
        });

        Schema::table('ingredient_stock_movements', function (Blueprint $table): void {
            $table->index(['ingredient_id', 'location_id', 'direction', 'occurred_at'], 'ism_ing_loc_dir_occ_idx');
            if (Schema::hasColumn('ingredient_stock_movements', 'product_id')) {
                $table->index(['product_id', 'location_id', 'direction', 'occurred_at'], 'ism_product_loc_dir_occ_idx');
            }
            if (Schema::hasColumn('ingredient_stock_movements', 'food_menu_id')) {
                $table->index(['food_menu_id', 'location_id', 'direction', 'occurred_at'], 'ism_food_loc_dir_occ_idx');
            }
            $table->index('reference', 'ism_reference_idx');
        });

        Schema::table('product_stock_movements', function (Blueprint $table): void {
            $table->index(['product_id', 'location_id', 'direction', 'occurred_at'], 'psm_product_loc_dir_occ_idx');
            $table->index(['location_id', 'occurred_at'], 'psm_loc_occ_idx');
            $table->index('reference', 'psm_reference_idx');
        });

        if (Schema::hasTable('refunds')) {
            Schema::table('refunds', function (Blueprint $table): void {
                $table->index(['sale_id', 'created_at'], 'refunds_sale_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex('sales_outlet_sale_status_idx');
            if (Schema::hasColumn('sales', 'cash_register_id')) {
                $table->dropIndex('sales_register_sale_idx');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_outlet_status_created_idx');
            $table->dropIndex('orders_outlet_stock_status_idx');
            $table->dropIndex('orders_table_status_idx');
        });

        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropIndex('purchases_loc_date_status_idx');
            $table->dropIndex('purchases_supplier_date_idx');
        });

        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropIndex('transfers_from_date_status_idx');
            $table->dropIndex('transfers_to_date_status_idx');
        });

        Schema::table('transfer_items', function (Blueprint $table): void {
            $table->dropIndex('transfer_items_type_item_idx');
        });

        Schema::table('ingredient_batches', function (Blueprint $table): void {
            $table->dropIndex('ib_loc_ing_qty_received_idx');
            if (Schema::hasColumn('ingredient_batches', 'product_id')) {
                $table->dropIndex('ib_loc_product_qty_received_idx');
            }
            if (Schema::hasColumn('ingredient_batches', 'food_menu_id')) {
                $table->dropIndex('ib_loc_food_qty_received_idx');
            }
        });

        Schema::table('ingredient_stock_movements', function (Blueprint $table): void {
            $table->dropIndex('ism_ing_loc_dir_occ_idx');
            if (Schema::hasColumn('ingredient_stock_movements', 'product_id')) {
                $table->dropIndex('ism_product_loc_dir_occ_idx');
            }
            if (Schema::hasColumn('ingredient_stock_movements', 'food_menu_id')) {
                $table->dropIndex('ism_food_loc_dir_occ_idx');
            }
            $table->dropIndex('ism_reference_idx');
        });

        Schema::table('product_stock_movements', function (Blueprint $table): void {
            $table->dropIndex('psm_product_loc_dir_occ_idx');
            $table->dropIndex('psm_loc_occ_idx');
            $table->dropIndex('psm_reference_idx');
        });

        if (Schema::hasTable('refunds')) {
            Schema::table('refunds', function (Blueprint $table): void {
                $table->dropIndex('refunds_sale_created_idx');
            });
        }
    }
};
