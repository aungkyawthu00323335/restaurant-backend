<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOutlet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure at least one Location/Outlet exists
        $outlet = Location::first();
        if (!$outlet) {
            $outlet = Location::create([
                'name' => 'Main Restaurant',
                'number' => '001',
                'email' => 'contact@mainresto.com',
                'phone' => '+1-555-1001',
                'address' => '100 Main St',
                'city' => 'New York',
                'state' => 'NY',
                'postal_code' => '10001',
                'country' => 'USA',
                'opening_time' => '08:00',
                'closing_time' => '23:00',
                'tax_identification_number' => 'TAX-MN-001',
                'is_head_office' => true,
                'is_active' => true,
            ]);
        }
        $allLocations = Location::all();

        // 2. Ensure Permissions exist
        $permissions = [
            ['module_name' => 'Main', 'permission_name' => 'view_dashboard', 'label' => 'Dashboard'],
            ['module_name' => 'Dashboard', 'permission_name' => 'dashboard.view', 'label' => 'Dashboard - View'],
            ['module_name' => 'Dashboard', 'permission_name' => 'dashboard.export', 'label' => 'Dashboard - Export'],

            ['module_name' => 'Sales & Operations', 'permission_name' => 'view_sales', 'label' => 'Sales List'],
            ['module_name' => 'Sales & Operations', 'permission_name' => 'view_orders', 'label' => 'Order Management'],
            ['module_name' => 'Sales & Operations', 'permission_name' => 'view_reservations', 'label' => 'Reservation'],
            ['module_name' => 'Sales & Operations', 'permission_name' => 'view_floor', 'label' => 'Floor Plans & Tables'],

            ['module_name' => 'Reservations', 'permission_name' => 'reservations.view', 'label' => 'Reservations - View'],
            ['module_name' => 'Reservations', 'permission_name' => 'reservations.create', 'label' => 'Reservations - Create'],
            ['module_name' => 'Reservations', 'permission_name' => 'reservations.edit', 'label' => 'Reservations - Edit'],
            ['module_name' => 'Reservations', 'permission_name' => 'reservations.cancel', 'label' => 'Reservations - Cancel'],
            ['module_name' => 'Reservations', 'permission_name' => 'reservations.seat', 'label' => 'Reservations - Seat'],

            ['module_name' => 'Orders', 'permission_name' => 'orders.view', 'label' => 'Orders - View'],
            ['module_name' => 'Orders', 'permission_name' => 'orders.create', 'label' => 'Orders - Create'],
            ['module_name' => 'Orders', 'permission_name' => 'orders.edit', 'label' => 'Orders - Edit'],
            ['module_name' => 'Orders', 'permission_name' => 'orders.send_kitchen', 'label' => 'Orders - Send Kitchen'],
            ['module_name' => 'Orders', 'permission_name' => 'orders.merge', 'label' => 'Orders - Merge'],
            ['module_name' => 'Orders', 'permission_name' => 'orders.split', 'label' => 'Orders - Split'],
            ['module_name' => 'Orders', 'permission_name' => 'orders.swap_table', 'label' => 'Orders - Swap Table'],
            ['module_name' => 'Orders', 'permission_name' => 'orders.cancel', 'label' => 'Orders - Cancel'],
            ['module_name' => 'Orders', 'permission_name' => 'orders.update_status', 'label' => 'Orders - Update Status'],

            ['module_name' => 'Panel', 'permission_name' => 'view_waiter_panel', 'label' => 'Waiter Panel'],
            ['module_name' => 'Panel', 'permission_name' => 'view_cashier_panel', 'label' => 'Cashier Panel'],
            ['module_name' => 'Panel', 'permission_name' => 'view_delivery', 'label' => 'Delivery'],

            ['module_name' => 'Cashier', 'permission_name' => 'cashier.view', 'label' => 'Cashier - View'],
            ['module_name' => 'Cashier', 'permission_name' => 'cashier.open_register', 'label' => 'Cashier - Open Register'],
            ['module_name' => 'Cashier', 'permission_name' => 'cashier.settle', 'label' => 'Cashier - Settle'],
            ['module_name' => 'Cashier', 'permission_name' => 'cashier.print_receipt', 'label' => 'Cashier - Print Receipt'],
            ['module_name' => 'Cashier', 'permission_name' => 'cashier.close_register', 'label' => 'Cashier - Close Register'],
            ['module_name' => 'Cashier', 'permission_name' => 'cashier.view_report', 'label' => 'Cashier - View Report'],

            ['module_name' => 'Food Menu', 'permission_name' => 'view_foodmenu_category', 'label' => 'Category'],
            ['module_name' => 'Food Menu', 'permission_name' => 'view_foodmenu_unit', 'label' => 'Food Menu Unit'],
            ['module_name' => 'Food Menu', 'permission_name' => 'view_foodmenu_create', 'label' => 'Food Menu Create'],
            ['module_name' => 'Food Menu', 'permission_name' => 'view_foodmenus', 'label' => 'Food Menu List'],
            ['module_name' => 'Food Menu', 'permission_name' => 'view_foodmenu_production', 'label' => 'Production & Production List'],
            ['module_name' => 'Food Menu', 'permission_name' => 'view_combo_menu', 'label' => 'Combo Menu'],
            ['module_name' => 'Food Menu', 'permission_name' => 'view_modifiers', 'label' => 'Modifiers & Modifier Categories'],

            ['module_name' => 'Product', 'permission_name' => 'view_product_categories', 'label' => 'Product Categories'],
            ['module_name' => 'Product', 'permission_name' => 'view_product_units', 'label' => 'Product Units'],
            ['module_name' => 'Product', 'permission_name' => 'view_products', 'label' => 'Product List'],

            ['module_name' => 'Ingredient', 'permission_name' => 'view_ingredient_categories', 'label' => 'Ingredient Category'],
            ['module_name' => 'Ingredient', 'permission_name' => 'view_purchase_units', 'label' => 'Purchase Unit'],
            ['module_name' => 'Ingredient', 'permission_name' => 'view_consumption_units', 'label' => 'Consumption Unit'],
            ['module_name' => 'Ingredient', 'permission_name' => 'view_ingredients', 'label' => 'Ingredient List'],
            ['module_name' => 'Ingredient', 'permission_name' => 'view_ingredient_processing', 'label' => 'Processing & Processing List'],

            ['module_name' => 'Inventory', 'permission_name' => 'view_suppliers', 'label' => 'Supplier List'],
            ['module_name' => 'Inventory', 'permission_name' => 'view_purchases', 'label' => 'Purchase List'],
            ['module_name' => 'Inventory', 'permission_name' => 'create_purchase', 'label' => 'Add Purchase'],
            ['module_name' => 'Inventory', 'permission_name' => 'view_report_inventory', 'label' => 'Stock Report'],
            ['module_name' => 'Purchases', 'permission_name' => 'purchases.view', 'label' => 'Purchases - View'],
            ['module_name' => 'Purchases', 'permission_name' => 'purchases.create', 'label' => 'Purchases - Create'],
            ['module_name' => 'Purchases', 'permission_name' => 'purchases.edit', 'label' => 'Purchases - Edit'],
            ['module_name' => 'Purchases', 'permission_name' => 'purchases.post', 'label' => 'Purchases - Post'],
            ['module_name' => 'Purchases', 'permission_name' => 'purchases.cancel', 'label' => 'Purchases - Cancel'],
            ['module_name' => 'Stock', 'permission_name' => 'stock.view', 'label' => 'Stock - View'],
            ['module_name' => 'Stock', 'permission_name' => 'stock.export', 'label' => 'Stock - Export'],

            ['module_name' => 'Transfer', 'permission_name' => 'create_transfer', 'label' => 'Create Transfer'],
            ['module_name' => 'Transfer', 'permission_name' => 'view_transfers', 'label' => 'Transfer List'],
            ['module_name' => 'Transfers', 'permission_name' => 'transfers.view', 'label' => 'Transfers - View'],
            ['module_name' => 'Transfers', 'permission_name' => 'transfers.create', 'label' => 'Transfers - Create'],
            ['module_name' => 'Transfers', 'permission_name' => 'transfers.send', 'label' => 'Transfers - Send'],
            ['module_name' => 'Transfers', 'permission_name' => 'transfers.receive', 'label' => 'Transfers - Receive'],
            ['module_name' => 'Transfers', 'permission_name' => 'transfers.cancel', 'label' => 'Transfers - Cancel'],

            ['module_name' => 'Customers', 'permission_name' => 'view_customers', 'label' => 'Customer List'],
            ['module_name' => 'Customers', 'permission_name' => 'create_customer', 'label' => 'Add Customer'],

            ['module_name' => 'Expense', 'permission_name' => 'view_expense_categories', 'label' => 'Expense Category List'],
            ['module_name' => 'Expense', 'permission_name' => 'view_expenses', 'label' => 'Expense List'],

            ['module_name' => 'Report', 'permission_name' => 'view_report_sales', 'label' => 'Register & Sale Reports'],
            ['module_name' => 'Report', 'permission_name' => 'view_report_zx', 'label' => 'Z/X Report'],
            ['module_name' => 'Report', 'permission_name' => 'view_report_profit_loss', 'label' => 'Profit & Loss Report'],

            ['module_name' => 'Setting', 'permission_name' => 'view_locations', 'label' => 'Location / Outlet'],
            ['module_name' => 'Setting', 'permission_name' => 'view_settings', 'label' => 'Currencies, Tax, Discounts & Charges'],
            ['module_name' => 'Setting', 'permission_name' => 'view_printers', 'label' => 'Printer Setup'],

            ['module_name' => 'Users', 'permission_name' => 'view_users', 'label' => 'User List'],
            ['module_name' => 'Users', 'permission_name' => 'create_user', 'label' => 'Add Users'],
            ['module_name' => 'Users', 'permission_name' => 'manage_roles', 'label' => 'Roles & Permissions'],
            ['module_name' => 'Users', 'permission_name' => 'view_activities', 'label' => 'User Activities'],
            ['module_name' => 'Audit Logs', 'permission_name' => 'audit_logs.view', 'label' => 'Audit Logs - View'],
            ['module_name' => 'Audit Logs', 'permission_name' => 'audit_logs.export', 'label' => 'Audit Logs - Export'],
            ['module_name' => 'Profile', 'permission_name' => 'profile.view', 'label' => 'Profile - View'],
            ['module_name' => 'Profile', 'permission_name' => 'profile.edit', 'label' => 'Profile - Edit'],
            ['module_name' => 'Profile', 'permission_name' => 'profile.change_password', 'label' => 'Profile - Change Password'],
        ];

        foreach ($permissions as $p) {
            Permission::query()->updateOrCreate(
                ['permission_name' => $p['permission_name']],
                [
                    'module_name' => $p['module_name'],
                    'label' => $p['label'],
                ]
            );
        }

        // 3. Super Admin Role
        $superAdminRole = Role::firstOrCreate(
            ['role_name' => 'Super Admin'],
            ['description' => 'Full access to all system modules, settings, outlets, and roles.', 'status' => 'active']
        );
        $allPermIds = Permission::pluck('id')->toArray();
        if (!empty($allPermIds)) {
            $superAdminRole->permissions()->sync($allPermIds);
        }

        // 4. Create / Update Super Admin users
        $superUsers = [
            [
                'username' => 'superadmin',
                'name' => 'Super Admin',
                'email' => 'superadmin@example.com',
                'password' => Hash::make('password123'),
                'employee_id' => 'EMP-000',
                'department' => 'Administration',
                'position' => 'Super Administrator',
            ],
            [
                'username' => 'admin',
                'name' => 'Super Admin',
                'email' => 'admin@example.com',
                'password' => Hash::make('admin123'),
                'employee_id' => 'EMP-001',
                'department' => 'Administration',
                'position' => 'Super Administrator',
            ],
        ];

        foreach ($superUsers as $userData) {
            $user = User::updateOrCreate(
                ['username' => $userData['username']],
                array_merge($userData, [
                    'role_id' => $superAdminRole->id,
                    'status' => 'active',
                    'joining_date' => now()->toDateString(),
                    'default_outlet_id' => $outlet->id,
                ])
            );

            foreach ($allLocations as $loc) {
                UserOutlet::firstOrCreate([
                    'user_id' => $user->id,
                    'outlet_id' => $loc->id,
                ]);
            }
        }
    }
}
