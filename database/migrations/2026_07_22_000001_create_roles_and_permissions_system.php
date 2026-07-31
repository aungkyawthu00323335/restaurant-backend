<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Roles table
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('role_name')->unique();
                $table->text('description')->nullable();
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }

        // 2. Permissions table
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('module_name');
                $table->string('permission_name')->unique();
                $table->string('label');
                $table->timestamps();
            });
        }

        // 3. Role Permissions table
        if (!Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
                $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
            });
        }

        // 4. User Override Permissions table
        if (!Schema::hasTable('user_permissions')) {
            Schema::create('user_permissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
                $table->boolean('is_allowed')->default(true);
            });
        }

        // 5. User Outlets table
        if (!Schema::hasTable('user_outlets')) {
            Schema::create('user_outlets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('outlet_id')->constrained('locations')->onDelete('cascade');
            });
        }

        // 6. Add columns to users table if missing
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'username')) {
                $table->string('username')->nullable()->unique()->after('name');
            }
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'role_id')) {
                $table->foreignId('role_id')->nullable()->after('password')->constrained('roles')->onDelete('set null');
            }
            if (!Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('active')->after('role_id');
            }
            if (!Schema::hasColumn('users', 'profile_image')) {
                $table->string('profile_image')->nullable()->after('status');
            }
            if (!Schema::hasColumn('users', 'employee_id')) {
                $table->string('employee_id')->nullable()->unique()->after('profile_image');
            }
            if (!Schema::hasColumn('users', 'department')) {
                $table->string('department')->nullable()->after('employee_id');
            }
            if (!Schema::hasColumn('users', 'position')) {
                $table->string('position')->nullable()->after('department');
            }
            if (!Schema::hasColumn('users', 'joining_date')) {
                $table->date('joining_date')->nullable()->after('position');
            }
            if (!Schema::hasColumn('users', 'default_outlet_id')) {
                $table->foreignId('default_outlet_id')->nullable()->after('joining_date')->constrained('locations')->onDelete('set null');
            }
        });

        // 7. Comprehensive Sidebar Permission List
        $permissions = [
            // Main
            ['module_name' => 'Main', 'permission_name' => 'view_dashboard', 'label' => 'Dashboard'],

            // Operations & Sales
            ['module_name' => 'Sales & Operations', 'permission_name' => 'view_sales', 'label' => 'Sales List'],
            ['module_name' => 'Sales & Operations', 'permission_name' => 'view_orders', 'label' => 'Order Management'],
            ['module_name' => 'Sales & Operations', 'permission_name' => 'view_reservations', 'label' => 'Reservation'],
            ['module_name' => 'Sales & Operations', 'permission_name' => 'view_floor', 'label' => 'Floor Plans & Tables'],

            // Panel Sub-Items
            ['module_name' => 'Panel', 'permission_name' => 'view_waiter_panel', 'label' => 'Waiter Panel'],
            ['module_name' => 'Panel', 'permission_name' => 'view_cashier_panel', 'label' => 'Cashier Panel'],
            ['module_name' => 'Panel', 'permission_name' => 'view_delivery', 'label' => 'Delivery'],

            // Food Menu Sub-Items
            ['module_name' => 'Food Menu', 'permission_name' => 'view_foodmenu_category', 'label' => 'Category'],
            ['module_name' => 'Food Menu', 'permission_name' => 'view_foodmenu_unit', 'label' => 'Food Menu Unit'],
            ['module_name' => 'Food Menu', 'permission_name' => 'view_foodmenu_create', 'label' => 'Food Menu Create'],
            ['module_name' => 'Food Menu', 'permission_name' => 'view_foodmenus', 'label' => 'Food Menu List'],
            ['module_name' => 'Food Menu', 'permission_name' => 'view_foodmenu_production', 'label' => 'Production & Production List'],
            ['module_name' => 'Food Menu', 'permission_name' => 'view_combo_menu', 'label' => 'Combo Menu'],
            ['module_name' => 'Food Menu', 'permission_name' => 'view_modifiers', 'label' => 'Modifiers & Modifier Categories'],

            // Product Sub-Items
            ['module_name' => 'Product', 'permission_name' => 'view_product_categories', 'label' => 'Product Categories'],
            ['module_name' => 'Product', 'permission_name' => 'view_product_units', 'label' => 'Product Units'],
            ['module_name' => 'Product', 'permission_name' => 'view_products', 'label' => 'Product List'],

            // Ingredient Sub-Items
            ['module_name' => 'Ingredient', 'permission_name' => 'view_ingredient_categories', 'label' => 'Ingredient Category'],
            ['module_name' => 'Ingredient', 'permission_name' => 'view_purchase_units', 'label' => 'Purchase Unit'],
            ['module_name' => 'Ingredient', 'permission_name' => 'view_consumption_units', 'label' => 'Consumption Unit'],
            ['module_name' => 'Ingredient', 'permission_name' => 'view_ingredients', 'label' => 'Ingredient List'],
            ['module_name' => 'Ingredient', 'permission_name' => 'view_ingredient_processing', 'label' => 'Processing & Processing List'],

            // Inventory & Logistics Sub-Items
            ['module_name' => 'Inventory', 'permission_name' => 'view_suppliers', 'label' => 'Supplier List'],
            ['module_name' => 'Inventory', 'permission_name' => 'view_purchases', 'label' => 'Purchase List'],
            ['module_name' => 'Inventory', 'permission_name' => 'create_purchase', 'label' => 'Add Purchase'],
            ['module_name' => 'Inventory', 'permission_name' => 'view_report_inventory', 'label' => 'Stock Report'],

            // Transfer Sub-Items
            ['module_name' => 'Transfer', 'permission_name' => 'create_transfer', 'label' => 'Create Transfer'],
            ['module_name' => 'Transfer', 'permission_name' => 'view_transfers', 'label' => 'Transfer List'],

            // Customers
            ['module_name' => 'Customers', 'permission_name' => 'view_customers', 'label' => 'Customer List'],
            ['module_name' => 'Customers', 'permission_name' => 'create_customer', 'label' => 'Add Customer'],

            // Expenses
            ['module_name' => 'Expense', 'permission_name' => 'view_expense_categories', 'label' => 'Expense Category List'],
            ['module_name' => 'Expense', 'permission_name' => 'view_expenses', 'label' => 'Expense List'],

            // Reports
            ['module_name' => 'Report', 'permission_name' => 'view_report_sales', 'label' => 'Register & Sale Reports'],
            ['module_name' => 'Report', 'permission_name' => 'view_report_zx', 'label' => 'Z/X Report'],
            ['module_name' => 'Report', 'permission_name' => 'view_report_profit_loss', 'label' => 'Profit & Loss Report'],

            // System Settings Sub-Items
            ['module_name' => 'Setting', 'permission_name' => 'view_locations', 'label' => 'Location / Outlet'],
            ['module_name' => 'Setting', 'permission_name' => 'view_settings', 'label' => 'Currencies, Tax, Discounts & Charges'],
            ['module_name' => 'Setting', 'permission_name' => 'view_printers', 'label' => 'Printer Setup'],

            // User Management Sub-Items
            ['module_name' => 'Users', 'permission_name' => 'view_users', 'label' => 'User List'],
            ['module_name' => 'Users', 'permission_name' => 'create_user', 'label' => 'Add Users'],
            ['module_name' => 'Users', 'permission_name' => 'manage_roles', 'label' => 'Roles & Permissions'],
            ['module_name' => 'Users', 'permission_name' => 'view_activities', 'label' => 'User Activities'],
        ];

        foreach ($permissions as $p) {
            DB::table('permissions')->updateOrInsert(
                ['permission_name' => $p['permission_name']],
                [
                    'module_name' => $p['module_name'],
                    'label' => $p['label'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Seed Roles
        $superAdmin = DB::table('roles')->where('role_name', 'Super Admin')->first();
        if (!$superAdmin) {
            $superAdminId = DB::table('roles')->insertGetId([
                'role_name' => 'Super Admin',
                'description' => 'Full access to all system modules, outlets, and settings.',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $superAdminId = $superAdmin->id;
        }

        // Assign All Permissions to Super Admin
        $allPermIds = DB::table('permissions')->pluck('id');
        foreach ($allPermIds as $pId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $superAdminId,
                'permission_id' => $pId,
            ]);
        }

        DB::table('users')->whereNull('role_id')->update(['role_id' => $superAdminId]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('user_outlets');
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::enableForeignKeyConstraints();
    }
};
