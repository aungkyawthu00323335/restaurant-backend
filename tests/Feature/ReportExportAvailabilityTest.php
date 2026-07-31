<?php

namespace Tests\Feature;

use App\Models\ConsumptionUnit;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\IngredientStockMovement;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\PurchaseUnit;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportExportAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_sidebar_report_exports_are_available(): void
    {
        User::create([
            'name' => 'Report User',
            'username' => 'reportuser',
            'email' => 'reportuser@example.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $paths = [
            '/api/v1/stock-report/export/excel',
            '/api/v1/stock-report/export/pdf',
            '/api/v1/purchase-report/export/excel',
            '/api/v1/purchase-report/export/pdf',
            '/api/v1/reports/transfers/export/excel',
            '/api/v1/reports/transfers/export/pdf',
            '/api/v1/reports/registers/excel',
            '/api/v1/reports/registers/pdf',
            '/api/v1/reports/sales/excel',
            '/api/v1/reports/sales/pdf',
            '/api/v1/reports/sales-by-category/excel',
            '/api/v1/reports/sales-by-category/pdf',
            '/api/v1/reports/sales-by-order-type/excel',
            '/api/v1/reports/sales-by-order-type/pdf',
            '/api/v1/reports/item-sales/excel',
            '/api/v1/reports/item-sales/pdf',
            '/api/v1/reports/sales-by-payment-method/excel',
            '/api/v1/reports/sales-by-payment-method/pdf',
            '/api/v1/reports/supplier/excel',
            '/api/v1/reports/supplier/pdf',
            '/api/v1/reports/tax/excel',
            '/api/v1/reports/tax/pdf',
            '/api/v1/reports/customer/excel',
            '/api/v1/reports/customer/pdf',
            '/api/v1/reports/staff/excel',
            '/api/v1/reports/staff/pdf',
            '/api/v1/reports/profit-loss/excel',
            '/api/v1/reports/profit-loss/pdf',
            '/api/v1/zx-report/excel',
            '/api/v1/zx-report/pdf',
            '/api/v1/ingredient-processings/export/excel',
            '/api/v1/ingredient-processings/export/pdf',
            '/api/v1/food-menu-productions/export/excel',
            '/api/v1/food-menu-productions/export/pdf',
            '/api/v1/stock-movement-history/export/excel',
            '/api/v1/stock-movement-history/export/pdf',
        ];

        foreach ($paths as $path) {
            $this->getJson($path)->assertOk();
        }
    }

    public function test_stock_movement_history_exports_dynamic_rows(): void
    {
        User::create([
            'name' => 'Report User',
            'username' => 'reportuser',
            'email' => 'reportuser@example.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $location = Location::create(['name' => 'Main Outlet', 'is_active' => true]);
        $category = IngredientCategory::create(['name' => 'Dry Goods', 'is_active' => true]);
        $purchaseUnit = PurchaseUnit::create(['name' => 'Bag', 'is_active' => true]);
        $consumptionUnit = ConsumptionUnit::create(['name' => 'Gram', 'is_active' => true]);
        $ingredient = Ingredient::create([
            'name' => 'Rice',
            'type' => 'single',
            'has_ingredient_mapping' => false,
            'ingredient_category_id' => $category->id,
            'purchase_unit_id' => $purchaseUnit->id,
            'consumption_unit_id' => $consumptionUnit->id,
            'conversion_rate' => 1000,
            'purchase_price' => 2000,
            'sku_code' => 'ING-RICE',
            'is_active' => true,
        ]);

        IngredientStockMovement::create([
            'ingredient_id' => $ingredient->id,
            'location_id' => $location->id,
            'direction' => 'IN',
            'reason_code' => 'purchase',
            'unit_type' => 'consumption',
            'quantity_input' => 500,
            'quantity_consumption' => 500,
            'batch_unit_cost' => 2,
            'reference' => 'PUR-TEST-001',
            'note' => 'Test purchase movement',
            'occurred_at' => '2026-07-29 09:00:00',
        ]);

        $query = '?location_id='.$location->id.'&date_from=2026-07-29&date_to=2026-07-29';

        $this->get('/api/v1/stock-movement-history/export/excel'.$query)
            ->assertOk()
            ->assertSee('Rice')
            ->assertSee('PUR-TEST-001');

        $this->get('/api/v1/stock-movement-history/export/pdf'.$query)
            ->assertOk()
            ->assertSee('Rice')
            ->assertSee('PUR-TEST-001');
    }

    public function test_sales_reports_use_refunds_balances_and_allocated_payment_amounts(): void
    {
        $user = User::create([
            'name' => 'Report User',
            'username' => 'reportuser',
            'email' => 'reportuser@example.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $location = Location::create(['name' => 'Main Outlet', 'is_active' => true]);
        $cash = PaymentMethod::create(['name' => 'Cash', 'is_active' => true]);

        $order = Order::create([
            'order_no' => 'ORD-REPORT-001',
            'outlet_id' => $location->id,
            'order_type' => 'dine_in',
            'customer_name' => 'Mg Mg',
            'subtotal' => 100,
            'grand_total' => 100,
            'paid_amount' => 120,
            'balance_amount' => 15,
            'change_amount' => 20,
            'order_status' => 'completed',
            'confirmation_status' => 'confirmed',
            'payment_state' => 'paid',
            'stock_deduction_status' => 'deducted',
            'created_by' => $user->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'item_type' => 'product',
            'item_id' => 1001,
            'item_name_snapshot' => 'Report Product',
            'unit_name_snapshot' => 'Plate',
            'qty' => 1,
            'original_qty' => 1,
            'active_qty' => 1,
            'cancelled_qty' => 0,
            'base_unit_price_snapshot' => 100,
            'final_unit_price' => 100,
            'discount_amount' => 0,
            'amount' => 100,
            'cost_snapshot' => 40,
        ]);

        $sale = Sale::create([
            'sale_no' => 'SALE-REPORT-001',
            'order_id' => $order->id,
            'outlet_id' => $location->id,
            'total_amount' => 100,
            'total_cost' => 40,
            'profit_amount' => 60,
            'sale_at' => '2026-07-29 10:00:00',
            'created_by' => $user->id,
            'status' => 'completed',
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'payment_method_id' => $cash->id,
            'payment_method_name_snapshot' => 'Cash',
            'amount' => 120,
        ]);

        DB::table('refunds')->insert([
            'refund_no' => 'RFND-REPORT-001',
            'sale_id' => $sale->id,
            'refund_type' => 'partial',
            'refund_amount' => 30,
            'return_to_stock' => false,
            'reason' => 'Report test refund',
            'created_by' => $user->id,
            'created_at' => '2026-07-29 11:00:00',
            'updated_at' => '2026-07-29 11:00:00',
        ]);

        $query = '?location_id='.$location->id.'&date_from=2026-07-29&date_to=2026-07-29';

        $sales = $this->getJson('/api/v1/reports/sales'.$query)->assertOk()->json();
        $this->assertSame(30.0, (float) $sales['data'][0]['returns']);
        $this->assertSame(70.0, (float) $sales['data'][0]['net_sales']);
        $this->assertSame(30.0, (float) $sales['data'][0]['gross_profit']);

        $customer = $this->getJson('/api/v1/reports/customer'.$query)->assertOk()->json();
        $this->assertSame(70.0, (float) $customer['data'][0]['net_total']);
        $this->assertSame(15.0, (float) $customer['data'][0]['due']);

        $payment = $this->getJson('/api/v1/reports/sales-by-payment-method'.$query)->assertOk()->json();
        $this->assertSame('Cash', $payment['data'][0]['payment_method']);
        $this->assertSame(100.0, (float) $payment['data'][0]['total_amount']);
        $this->assertSame(30.0, (float) $payment['data'][0]['refunds']);
        $this->assertSame(70.0, (float) $payment['data'][0]['net_amount']);

        $category = $this->getJson('/api/v1/reports/sales-by-category'.$query)->assertOk()->json();
        $this->assertSame(30.0, (float) $category['data'][0]['returns']);
        $this->assertSame(70.0, (float) $category['data'][0]['net_sales']);

        $item = $this->getJson('/api/v1/reports/item-sales'.$query)->assertOk()->json();
        $this->assertSame(30.0, (float) $item['data'][0]['returns']);
        $this->assertSame(70.0, (float) $item['data'][0]['net_sales']);
    }
}
