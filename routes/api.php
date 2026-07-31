<?php

use App\Http\Controllers\Api\CashierPanelController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ChargeController;
use App\Http\Controllers\Api\ComboMenuController;
use App\Http\Controllers\Api\ConsumptionUnitController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\FloorController;
use App\Http\Controllers\Api\FoodMenuController;
use App\Http\Controllers\Api\FoodMenuUnitController;
use App\Http\Controllers\Api\FoodMenuProductionController;
use App\Http\Controllers\Api\GiftCardController;
use App\Http\Controllers\Api\IngredientCategoryController;
use App\Http\Controllers\Api\IngredientController;
use App\Http\Controllers\Api\IngredientProcessingController;
use App\Http\Controllers\Api\IngredientStockMovementController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\ModifierController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\InventoryAdjustmentController;
use App\Http\Controllers\Api\PurchaseReportController;
use App\Http\Controllers\Api\PrinterController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductUnitController;
use App\Http\Controllers\Api\PurchaseUnitController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\StockReportController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Api\TaxRateController;
use App\Http\Controllers\Api\WaiterPanelController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SecurityLogController;
use App\Http\Controllers\Api\ZXReportController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\ProfitLossReportController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware(['pos.api', 'outlet.context', 'throttle:120,1'])
    ->group(function (): void {

        // Public Auth login endpoint
        Route::post('auth/login', [AuthController::class, 'login']);

        // Protected session routes
        Route::middleware(['user.auth', 'outlet.context', 'outlet.access', 'permission'])->group(function (): void {
            Route::get('auth/me', [AuthController::class, 'me']);
            Route::post('auth/logout', [AuthController::class, 'logout']);
            Route::match(['put', 'post'], 'auth/profile', [\App\Http\Controllers\Api\ProfileController::class, 'update']);
            Route::get('auth/profile/activity-log', [\App\Http\Controllers\Api\ProfileController::class, 'activityLog']);

            // Dashboard
            Route::get('dashboard/admin', [\App\Http\Controllers\Api\AdminDashboardController::class, 'index']);

            // Notifications
            Route::get('notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
            Route::post('notifications/{id}/mark-read', [\App\Http\Controllers\Api\NotificationController::class, 'markAsRead']);
            Route::post('notifications/mark-all-read', [\App\Http\Controllers\Api\NotificationController::class, 'markAllAsRead']);

            // User & Role Management
            Route::get('users-list-data', [UserController::class, 'listData']);
            Route::apiResource('users', UserController::class);

            Route::get('roles/matrix', [RoleController::class, 'matrix']);
            Route::apiResource('roles', RoleController::class);

            // Security Logs Audit
            Route::get('security/login-history', [SecurityLogController::class, 'loginHistory']);
            Route::get('security/activity-logs', [SecurityLogController::class, 'activityLogs']);

            // Pre-existing POS catalog routes
            Route::apiResource('categories', CategoryController::class);
            Route::apiResource('ingredient-categories', IngredientCategoryController::class);
            Route::apiResource('purchase-units', PurchaseUnitController::class);
            Route::apiResource('consumption-units', ConsumptionUnitController::class);
            Route::get('ingredients/import-template', [\App\Http\Controllers\Api\IngredientController::class, 'downloadImportTemplate']);
            Route::get('ingredients/export/excel', [\App\Http\Controllers\Api\IngredientController::class, 'exportExcel']);
            Route::get('ingredients/export/pdf', [\App\Http\Controllers\Api\IngredientController::class, 'exportPdf']);
            Route::post('ingredients/import', [\App\Http\Controllers\Api\IngredientController::class, 'importIngredients']);
            Route::apiResource('ingredients', IngredientController::class);
            Route::apiResource('purchases', PurchaseController::class);
            Route::get('reservations/available-tables', [ReservationController::class, 'availableTables']);
            Route::apiResource('reservations', ReservationController::class);
            
            // Expense Management
            Route::apiResource('expense-categories', ExpenseCategoryController::class);
            Route::apiResource('expenses', ExpenseController::class);
            Route::get('expense-form-data', [ExpenseController::class, 'formData']);

            Route::get('stock-report', [StockReportController::class, 'index']);
            Route::get('stock-report/export/excel', [StockReportController::class, 'exportExcel']);
            Route::get('stock-report/export/pdf', [StockReportController::class, 'exportPdf']);
            Route::get('purchase-report', [PurchaseReportController::class, 'index']);
            Route::get('purchase-report/export/excel', [PurchaseReportController::class, 'exportExcel']);
            Route::get('purchase-report/export/pdf', [PurchaseReportController::class, 'exportPdf']);
            
            // Transfers
            Route::apiResource('inventory/transfers', \App\Http\Controllers\Api\TransferController::class)->except(['store']);
            Route::post('inventory/transfers', [\App\Http\Controllers\Api\TransferController::class, 'store'])
                ->middleware('idempotent');
            Route::middleware('report.log')->group(function () {
                Route::get('reports/transfers', [\App\Http\Controllers\Api\TransferReportController::class, 'index']);
                Route::get('reports/transfers/export/excel', [\App\Http\Controllers\Api\TransferReportController::class, 'exportExcel']);
                Route::get('reports/transfers/export/pdf', [\App\Http\Controllers\Api\TransferReportController::class, 'exportPdf']);
                Route::get('reports/registers', [\App\Http\Controllers\Api\RegisterReportController::class, 'index']);
                Route::get('reports/registers/pdf', [\App\Http\Controllers\Api\RegisterReportController::class, 'exportPdf']);
                Route::get('reports/registers/excel', [\App\Http\Controllers\Api\RegisterReportController::class, 'exportExcel']);
                Route::get('reports/sales', [\App\Http\Controllers\Api\SaleReportController::class, 'index']);
                Route::get('reports/sales/excel', [\App\Http\Controllers\Api\SaleReportController::class, 'exportExcel']);
                Route::get('reports/sales/pdf', [\App\Http\Controllers\Api\SaleReportController::class, 'exportPdf']);
                Route::get('reports/sales-by-category', [\App\Http\Controllers\Api\SalesByCategoryReportController::class, 'index']);
                Route::get('reports/sales-by-category/pdf', [\App\Http\Controllers\Api\SalesByCategoryReportController::class, 'exportPdf']);
                Route::get('reports/sales-by-category/excel', [\App\Http\Controllers\Api\SalesByCategoryReportController::class, 'exportExcel']);
                Route::get('reports/sales-by-order-type', [\App\Http\Controllers\Api\SalesByOrderTypeReportController::class, 'index']);
                Route::get('reports/sales-by-order-type/pdf', [\App\Http\Controllers\Api\SalesByOrderTypeReportController::class, 'exportPdf']);
                Route::get('reports/sales-by-order-type/excel', [\App\Http\Controllers\Api\SalesByOrderTypeReportController::class, 'exportExcel']);
                Route::get('reports/item-sales', [\App\Http\Controllers\Api\ItemSalesReportController::class, 'index']);
                Route::get('reports/item-sales/pdf', [\App\Http\Controllers\Api\ItemSalesReportController::class, 'exportPdf']);
                Route::get('reports/item-sales/excel', [\App\Http\Controllers\Api\ItemSalesReportController::class, 'exportExcel']);
                Route::get('reports/sales-by-payment-method', [\App\Http\Controllers\Api\SalesByPaymentMethodReportController::class, 'index']);
                Route::get('reports/sales-by-payment-method/pdf', [\App\Http\Controllers\Api\SalesByPaymentMethodReportController::class, 'exportPdf']);
                Route::get('reports/sales-by-payment-method/excel', [\App\Http\Controllers\Api\SalesByPaymentMethodReportController::class, 'exportExcel']);
                Route::get('reports/supplier', [\App\Http\Controllers\Api\SupplierReportController::class, 'index']);
                Route::get('reports/supplier/pdf', [\App\Http\Controllers\Api\SupplierReportController::class, 'exportPdf']);
                Route::get('reports/supplier/excel', [\App\Http\Controllers\Api\SupplierReportController::class, 'exportExcel']);
                Route::get('reports/tax', [\App\Http\Controllers\Api\TaxReportController::class, 'index']);
                Route::get('reports/tax/pdf', [\App\Http\Controllers\Api\TaxReportController::class, 'exportPdf']);
                Route::get('reports/tax/excel', [\App\Http\Controllers\Api\TaxReportController::class, 'exportExcel']);
                Route::get('reports/customer', [\App\Http\Controllers\Api\CustomerReportController::class, 'index']);
                Route::get('reports/customer/pdf', [\App\Http\Controllers\Api\CustomerReportController::class, 'exportPdf']);
                Route::get('reports/customer/excel', [\App\Http\Controllers\Api\CustomerReportController::class, 'exportExcel']);
                Route::get('reports/staff', [\App\Http\Controllers\Api\StaffReportController::class, 'index']);
                Route::get('reports/staff/pdf', [\App\Http\Controllers\Api\StaffReportController::class, 'exportPdf']);
                Route::get('reports/staff/excel', [\App\Http\Controllers\Api\StaffReportController::class, 'exportExcel']);
                Route::get('reports/profit-loss', [ProfitLossReportController::class, 'index']);
                Route::get('reports/profit-loss/pdf', [ProfitLossReportController::class, 'exportPdf']);
                Route::get('reports/profit-loss/excel', [ProfitLossReportController::class, 'exportExcel']);
                Route::get('zx-report', [ZXReportController::class, 'index']);
                Route::get('zx-report/excel', [ZXReportController::class, 'exportExcel']);
                Route::get('zx-report/pdf', [ZXReportController::class, 'exportPdf']);
                Route::get('zx-report/{id}', [ZXReportController::class, 'show']);
            });
            Route::middleware('idempotent')->group(function (): void {
                Route::post('inventory/prep-yield', [InventoryAdjustmentController::class, 'prepYield']);
                Route::post('inventory/transfer', [InventoryAdjustmentController::class, 'transfer']);
                Route::post('inventory/audit', [InventoryAdjustmentController::class, 'audit']);
                Route::post('inventory/decompose', [InventoryAdjustmentController::class, 'decompose']);
                Route::post('food-menu-productions', [FoodMenuProductionController::class, 'store']);
                Route::post('ingredient-processings', [IngredientProcessingController::class, 'store']);
            });
            Route::get('ingredient-processings', [IngredientProcessingController::class, 'index']);
            Route::get('ingredient-processings/export/excel', [IngredientProcessingController::class, 'exportExcel']);
            Route::get('ingredient-processings/export/pdf', [IngredientProcessingController::class, 'exportPdf']);
            Route::post('ingredient-processings/preview', [IngredientProcessingController::class, 'preview']);
            Route::get('ingredient-processings/{ingredientProcessing}', [IngredientProcessingController::class, 'show']);
            Route::post('ingredient-processings/{ingredientProcessing}/reverse', [IngredientProcessingController::class, 'reverse']);
            Route::get('ingredient-stock-movements/report', [IngredientStockMovementController::class, 'report']);
            Route::get('stock-movement-history', [IngredientStockMovementController::class, 'history']);
            Route::get('stock-movement-history/export/excel', [IngredientStockMovementController::class, 'exportHistoryExcel']);
            Route::get('stock-movement-history/export/pdf', [IngredientStockMovementController::class, 'exportHistoryPdf']);
            Route::apiResource('ingredient-stock-movements', IngredientStockMovementController::class);
            Route::apiResource('food-menu-units', FoodMenuUnitController::class);
            Route::apiResource('food-menus', FoodMenuController::class);
            Route::apiResource('modifiers', ModifierController::class);
            Route::get('locations/create-data', [LocationController::class, 'createData']);
            Route::apiResource('locations', LocationController::class);
            Route::apiResource('currencies', CurrencyController::class);
            Route::apiResource('payment-methods', PaymentMethodController::class);
            Route::post('printers/{printer}/test', [PrinterController::class, 'testPrint']);
            Route::get('food-menu-productions', [FoodMenuProductionController::class, 'index']);
            Route::get('food-menu-productions/export/excel', [FoodMenuProductionController::class, 'exportExcel']);
            Route::get('food-menu-productions/export/pdf', [FoodMenuProductionController::class, 'exportPdf']);
            Route::get('food-menu-productions/create-data', [FoodMenuProductionController::class, 'createData']);
            Route::get('food-menu-productions/preview', [FoodMenuProductionController::class, 'preview']);
            Route::get('food-menu-productions/{foodMenuProduction}', [FoodMenuProductionController::class, 'show']);
            Route::post('food-menu-productions/{foodMenuProduction}/reverse', [FoodMenuProductionController::class, 'reverse']);
            Route::get('products', [ProductController::class, 'index']);
            Route::get('products/create-data', [ProductController::class, 'createData']);
            Route::get('products/import-template', [ProductController::class, 'downloadImportTemplate']);
            Route::post('products/import', [ProductController::class, 'importProducts']);
            Route::get('products/export/excel', [ProductController::class, 'exportExcel']);
            Route::get('products/export/pdf', [ProductController::class, 'exportPdf']);
            Route::post('products', [ProductController::class, 'store']);
            Route::get('products/trash', [ProductController::class, 'trash']);
            Route::get('products/{product}', [ProductController::class, 'show']);
            Route::put('products/{product}', [ProductController::class, 'update']);
            Route::delete('products/{product}', [ProductController::class, 'destroy']);
            Route::patch('products/{product}/status', [ProductController::class, 'toggleStatus']);
            Route::get('products/{product}/stock-movements', [ProductController::class, 'stockMovements']);
            Route::post('products/{id}/restore', [ProductController::class, 'restore']);
            Route::delete('products/{id}/force-delete', [ProductController::class, 'forceDelete']);
            Route::get('product-categories', [ProductCategoryController::class, 'index']);
            Route::get('product-categories/create-data', [ProductCategoryController::class, 'createData']);
            Route::post('product-categories', [ProductCategoryController::class, 'store']);
            Route::get('product-categories/{productCategory}', [ProductCategoryController::class, 'show']);
            Route::put('product-categories/{productCategory}', [ProductCategoryController::class, 'update']);
            Route::patch('product-categories/{productCategory}/status', [ProductCategoryController::class, 'toggleStatus']);
            Route::delete('product-categories/{productCategory}', [ProductCategoryController::class, 'destroy']);
            Route::get('product-units', [ProductUnitController::class, 'index']);
            Route::get('product-units/create-data', [ProductUnitController::class, 'createData']);
            Route::post('product-units', [ProductUnitController::class, 'store']);
            Route::get('product-units/{productUnit}', [ProductUnitController::class, 'show']);
            Route::put('product-units/{productUnit}', [ProductUnitController::class, 'update']);
            Route::patch('product-units/{productUnit}/status', [ProductUnitController::class, 'toggleStatus']);
            Route::delete('product-units/{productUnit}', [ProductUnitController::class, 'destroy']);
            Route::get('combo-menus', [ComboMenuController::class, 'index']);
            Route::get('combo-menus/create-data', [ComboMenuController::class, 'createData']);
            Route::post('combo-menus', [ComboMenuController::class, 'store']);
            Route::get('combo-menus/{comboMenu}', [ComboMenuController::class, 'show']);
            Route::put('combo-menus/{comboMenu}', [ComboMenuController::class, 'update']);
            Route::patch('combo-menus/{comboMenu}/status', [ComboMenuController::class, 'toggleStatus']);
            Route::delete('combo-menus/{comboMenu}', [ComboMenuController::class, 'destroy']);
            Route::apiResource('printers', PrinterController::class);
            Route::apiResource('charges', ChargeController::class);
            Route::apiResource('coupons', CouponController::class);
            Route::apiResource('discounts', DiscountController::class);
            Route::apiResource('tax-rates', TaxRateController::class);
            Route::get('suppliers/import-template', [\App\Http\Controllers\Api\SupplierController::class, 'downloadImportTemplate']);
            Route::get('suppliers/export/excel', [\App\Http\Controllers\Api\SupplierController::class, 'exportExcel']);
            Route::get('suppliers/export/pdf', [\App\Http\Controllers\Api\SupplierController::class, 'exportPdf']);
            Route::post('suppliers/import', [\App\Http\Controllers\Api\SupplierController::class, 'importSuppliers']);
            Route::apiResource('suppliers', SupplierController::class);

            Route::get('customers/import-template', [\App\Http\Controllers\Api\CustomerController::class, 'downloadImportTemplate']);
            Route::get('customers/export/excel', [\App\Http\Controllers\Api\CustomerController::class, 'exportExcel']);
            Route::get('customers/export/pdf', [\App\Http\Controllers\Api\CustomerController::class, 'exportPdf']);
            Route::post('customers/import', [\App\Http\Controllers\Api\CustomerController::class, 'importCustomers']);
            Route::apiResource('customers', CustomerController::class);
            Route::apiResource('gift-cards', GiftCardController::class);

            // Floor
            Route::get('floors', [FloorController::class, 'index']);
            Route::get('floors/create-data', [FloorController::class, 'createData']);
            Route::post('floors', [FloorController::class, 'store']);
            Route::get('floors/{id}', [FloorController::class, 'show']);
            Route::put('floors/{id}', [FloorController::class, 'update']);
            Route::patch('floors/{id}/status', [FloorController::class, 'toggleStatus']);
            Route::delete('floors/{id}', [FloorController::class, 'destroy']);

            // Table
            Route::get('tables', [TableController::class, 'index']);
            Route::get('tables/create-data', [TableController::class, 'createData']);
            Route::post('tables', [TableController::class, 'store']);
            Route::get('tables/{id}', [TableController::class, 'show']);
            Route::put('tables/{id}', [TableController::class, 'update']);
            Route::patch('tables/{id}/status', [TableController::class, 'toggleStatus']);
            Route::delete('tables/{id}', [TableController::class, 'destroy']);

            // Deliveries
            Route::get('deliveries', [DeliveryController::class, 'index']);
            Route::post('deliveries', [DeliveryController::class, 'store']);
            Route::put('deliveries/{id}', [DeliveryController::class, 'update']);
            Route::patch('deliveries/{id}/status', [DeliveryController::class, 'toggleStatus']);
            Route::delete('deliveries/{id}', [DeliveryController::class, 'destroy']);

            // Waiter Panel
            Route::get('waiter-panel/create-data', [WaiterPanelController::class, 'createData']);
            Route::get('waiter-panel/menu-data', [WaiterPanelController::class, 'menuData']);
            Route::get('waiter-panel/tables', [WaiterPanelController::class, 'tables']);
            Route::get('waiter-panel/items', [WaiterPanelController::class, 'items']);
            Route::get('waiter-panel/orders', [WaiterPanelController::class, 'orders']);
            Route::get('waiter-panel/orders/{id}', [WaiterPanelController::class, 'showOrder']);
            Route::middleware('idempotent')->group(function (): void {
                Route::post('waiter-panel/orders', [WaiterPanelController::class, 'storeOrder']);
                Route::post('waiter-panel/orders/{id}/add-items', [WaiterPanelController::class, 'addItems']);
                Route::post('waiter-panel/orders/{id}/confirm', [WaiterPanelController::class, 'confirmOrder']);
                Route::post('waiter-panel/orders/{id}/payment', [WaiterPanelController::class, 'completePayment']);
            });
            Route::put('waiter-panel/orders/{id}', [WaiterPanelController::class, 'updateOrder']);
            Route::post('waiter-panel/orders/{id}/cancel', [WaiterPanelController::class, 'cancelOrder']);
            Route::post('waiter-panel/orders/{id}/items/{itemId}/cancel', [WaiterPanelController::class, 'cancelItem']);
            Route::post('waiter-panel/orders/{id}/status', [WaiterPanelController::class, 'updateStatus']);
            Route::post('waiter-panel/orders/{id}/reprint', [WaiterPanelController::class, 'reprint']);
            Route::post('waiter-panel/orders/{id}/adjustments', [WaiterPanelController::class, 'updateAdjustments']);
            Route::get('waiter-panel/orders/{id}/split-data', [WaiterPanelController::class, 'splitData']);
            Route::post('waiter-panel/orders/{id}/split', [WaiterPanelController::class, 'splitOrder']);
            Route::get('waiter-panel/tables/{id}/merge-options', [WaiterPanelController::class, 'mergeOptions']);
            Route::post('waiter-panel/tables/{id}/merge', [WaiterPanelController::class, 'mergeTables']);
            Route::get('waiter-panel/tables/{id}/activity', [WaiterPanelController::class, 'tableActivity']);
            Route::get('waiter-panel/tables/{id}/swap-options', [WaiterPanelController::class, 'swapOptions']);
            Route::post('waiter-panel/tables/{id}/swap', [WaiterPanelController::class, 'swapTable']);
            Route::post('waiter-panel/table-merge-groups/{id}/unmerge', [WaiterPanelController::class, 'unmergeTables']);
            Route::get('waiter-panel/table-merge-groups/{id}', [WaiterPanelController::class, 'showMergeGroup']);
            Route::get('waiter-panel/reservations', [WaiterPanelController::class, 'reservations']);
            Route::post('waiter-panel/reservations/{id}/arrived', [WaiterPanelController::class, 'markReservationArrived']);
            Route::post('waiter-panel/reservations/{id}/seat', [WaiterPanelController::class, 'seatReservation']);

            // Cashier Panel
            Route::get('cashier-panel/create-data', [CashierPanelController::class, 'createData']);
            Route::get('cashier-panel/register', [CashierPanelController::class, 'registerStatus']);
            Route::post('cashier-panel/register/open', [CashierPanelController::class, 'openRegister']);
            Route::post('cashier-panel/register/{id}/close', [CashierPanelController::class, 'closeRegister']);
            Route::get('cashier-panel/orders', [CashierPanelController::class, 'orders']);
            Route::get('cashier-panel/orders/{id}', [CashierPanelController::class, 'showOrder']);
            Route::post('cashier-panel/orders/{id}/calculate-payment', [CashierPanelController::class, 'calculatePayment']);
            Route::post('cashier-panel/orders/{id}/complete-payment', [CashierPanelController::class, 'completePayment'])
                ->middleware('idempotent');
            Route::post('cashier-panel/orders/{id}/update-charges', [CashierPanelController::class, 'updateCharges']);
            Route::post('cashier-panel/orders/{id}/print-check', [CashierPanelController::class, 'printCheck']);
            Route::post('cashier-panel/orders/{id}/print-bill', [CashierPanelController::class, 'printBill']);
            Route::post('cashier-panel/orders/{id}/cancel', [CashierPanelController::class, 'cancelOrder']);
            Route::get('cashier-panel/sales', [CashierPanelController::class, 'indexSales']);
            Route::get('cashier-panel/sales/{id}', [CashierPanelController::class, 'showSale']);
            Route::post('cashier-panel/sales/{id}/reprint', [CashierPanelController::class, 'reprintSaleBill']);
            Route::post('cashier-panel/sales/{id}/void', [CashierPanelController::class, 'voidSale']);
            Route::post('cashier-panel/sales/{id}/refund', [CashierPanelController::class, 'refundSale']);

            // Sprint 5 Advanced Enterprise Routes
            // 1. Third-Party Delivery Webhooks
            Route::post('webhooks/delivery/{provider}', [\App\Http\Controllers\Api\DeliveryWebhookController::class, 'handleWebhook']);
            Route::post('webhooks/delivery/{provider}/sync-menu', [\App\Http\Controllers\Api\DeliveryWebhookController::class, 'syncMenu']);

            // 2. Kitchen Display System (KDS)
            Route::get('kds/tickets', [\App\Http\Controllers\Api\KDSController::class, 'getTickets']);
            Route::patch('kds/tickets/{id}/status', [\App\Http\Controllers\Api\KDSController::class, 'updateTicketStatus']);
            Route::post('kds/tickets/{id}/bump', [\App\Http\Controllers\Api\KDSController::class, 'bumpTicket']);

            // 3. Offline POS Sync Batch
            Route::post('pos/sync-batch', [\App\Http\Controllers\Api\PosSyncController::class, 'syncBatch'])
                ->middleware('idempotent');
        });
    });
