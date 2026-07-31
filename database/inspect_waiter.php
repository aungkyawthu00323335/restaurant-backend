<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;

$waiterUser = User::where('username', 'waiter')->first();
if ($waiterUser) {
    echo "USER: " . $waiterUser->username . " (ID: " . $waiterUser->id . ")\n";
    echo "ROLE: " . ($waiterUser->role ? $waiterUser->role->role_name : 'NONE') . "\n";
    echo "PERMISSIONS:\n";
    print_r($waiterUser->getAllPermissions());
} else {
    echo "Waiter user not found.\n";
}

$allPerms = Permission::all()->pluck('permission_name')->toArray();
echo "\nALL DATABASE PERMISSIONS:\n";
print_r($allPerms);
