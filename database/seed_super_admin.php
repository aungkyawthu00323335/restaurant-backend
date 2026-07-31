<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Role;
use App\Models\Permission;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

try {
    $outlet = Location::first();
    if (!$outlet) {
        $outlet = Location::create([
            'name' => 'Main Restaurant',
            'code' => 'OUT-001',
            'status' => 'active',
        ]);
    }
    $allLocations = Location::pluck('id')->toArray();

    // 1. Super Admin Role
    $superRole = Role::firstOrCreate(
        ['role_name' => 'Super Admin'],
        ['description' => 'Full access to all system modules, settings, outlets, and roles.', 'status' => 'active']
    );
    $allPermIds = Permission::pluck('id')->toArray();
    $superRole->permissions()->sync($allPermIds);

    $superUser = User::where('username', 'superadmin')->first();
    if (!$superUser) {
        $superUser = new User();
        $superUser->username = 'superadmin';
    }
    $superUser->name = 'Super Admin';
    $superUser->email = 'superadmin@pos.com';
    $superUser->password = Hash::make('password123');
    $superUser->role_id = $superRole->id;
    $superUser->status = 'active';
    $superUser->default_outlet_id = $outlet->id;
    $superUser->save();
    $superUser->outlets()->sync($allLocations);

    // 2. Cashier Role & User
    $cashierRole = Role::firstOrCreate(
        ['role_name' => 'Cashier'],
        ['description' => 'Access to Cashier Panel, Sales, and Register Reports.', 'status' => 'active']
    );
    $cashierPerms = Permission::whereIn('permission_name', [
        'view_cashier_panel',
        'view_sales',
        'view_orders',
        'view_report_sales',
        'view_report_zx',
    ])->pluck('id')->toArray();
    $cashierRole->permissions()->sync($cashierPerms);

    $cashierUser = User::where('username', 'cashier')->first();
    if (!$cashierUser) {
        $cashierUser = new User();
        $cashierUser->username = 'cashier';
    }
    $cashierUser->name = 'Main Cashier';
    $cashierUser->email = 'cashier@pos.com';
    $cashierUser->password = Hash::make('password123');
    $cashierUser->role_id = $cashierRole->id;
    $cashierUser->status = 'active';
    $cashierUser->default_outlet_id = $outlet->id;
    $cashierUser->save();
    $cashierUser->outlets()->sync($allLocations);

    // 3. Waiter Role & User
    $waiterRole = Role::firstOrCreate(
        ['role_name' => 'Waiter'],
        ['description' => 'Access to Waiter Panel, Tables, and Delivery.', 'status' => 'active']
    );
    $waiterPerms = Permission::whereIn('permission_name', [
        'view_waiter_panel',
        'view_orders',
        'view_reservations',
        'view_floor',
        'view_delivery',
    ])->pluck('id')->toArray();
    $waiterRole->permissions()->sync($waiterPerms);

    $waiterUser = User::where('username', 'waiter')->first();
    if (!$waiterUser) {
        $waiterUser = new User();
        $waiterUser->username = 'waiter';
    }
    $waiterUser->name = 'Senior Waiter';
    $waiterUser->email = 'waiter@pos.com';
    $waiterUser->password = Hash::make('password123');
    $waiterUser->role_id = $waiterRole->id;
    $waiterUser->status = 'active';
    $waiterUser->default_outlet_id = $outlet->id;
    $waiterUser->save();
    $waiterUser->outlets()->sync($allLocations);

    // 4. Store Manager Role & User
    $managerRole = Role::firstOrCreate(
        ['role_name' => 'Manager'],
        ['description' => 'Access to Inventory, Purchases, Suppliers, Ingredients, Products, and Reports.', 'status' => 'active']
    );
    $managerPerms = Permission::whereIn('module_name', [
        'Ingredient', 'Product', 'Inventory', 'Transfer', 'Expense', 'Report'
    ])->pluck('id')->toArray();
    $managerRole->permissions()->sync($managerPerms);

    $managerUser = User::where('username', 'manager')->first();
    if (!$managerUser) {
        $managerUser = new User();
        $managerUser->username = 'manager';
    }
    $managerUser->name = 'Store Manager';
    $managerUser->email = 'manager@pos.com';
    $managerUser->password = Hash::make('password123');
    $managerUser->role_id = $managerRole->id;
    $managerUser->status = 'active';
    $managerUser->default_outlet_id = $outlet->id;
    $managerUser->save();
    $managerUser->outlets()->sync($allLocations);

    echo "\n============================================\n";
    echo " DEMO USERS & ROLES SEEDED SUCCESSFULLY!\n";
    echo "============================================\n";
    echo " ⚡ superadmin  : password123 (Role ID: {$superRole->id})\n";
    echo " 🛒 cashier     : password123 (Role ID: {$cashierRole->id})\n";
    echo " 🍽️ waiter      : password123 (Role ID: {$waiterRole->id})\n";
    echo " 📦 manager     : password123 (Role ID: {$managerRole->id})\n";
    echo "============================================\n\n";

} catch (\Exception $e) {
    echo "Error seeding users: " . $e->getMessage() . "\n";
}
