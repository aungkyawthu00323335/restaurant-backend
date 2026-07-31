<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'dashboard.view', 'dashboard.export',
            'reservations.view', 'reservations.create', 'reservations.edit', 'reservations.cancel', 'reservations.seat',
            'orders.view', 'orders.create', 'orders.edit', 'orders.send_kitchen', 'orders.merge', 'orders.split', 'orders.swap_table', 'orders.cancel', 'orders.update_status',
            'cashier.view', 'cashier.open_register', 'cashier.settle', 'cashier.print_receipt', 'cashier.close_register', 'cashier.view_report',
            'purchases.view', 'purchases.create', 'purchases.edit', 'purchases.post', 'purchases.cancel',
            'stock.view', 'stock.export', 'transfers.view', 'transfers.create', 'transfers.send', 'transfers.receive', 'transfers.cancel',
            'audit_logs.view', 'audit_logs.export', 'profile.view', 'profile.edit', 'profile.change_password',
        ];

        foreach ($permissions as $permission) {
            [$module] = explode('.', $permission, 2);
            DB::table('permissions')->updateOrInsert(
                ['permission_name' => $permission],
                [
                    'module_name' => ucwords(str_replace('_', ' ', $module)),
                    'label' => ucwords(str_replace(['.', '_'], [' - ', ' '], $permission)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $superAdminId = DB::table('roles')->where('role_name', 'Super Admin')->value('id');
        if ($superAdminId) {
            $permissionIds = DB::table('permissions')->whereIn('permission_name', $permissions)->pluck('id');
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $superAdminId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('permission_name', [
            'dashboard.view', 'dashboard.export',
            'reservations.view', 'reservations.create', 'reservations.edit', 'reservations.cancel', 'reservations.seat',
            'orders.view', 'orders.create', 'orders.edit', 'orders.send_kitchen', 'orders.merge', 'orders.split', 'orders.swap_table', 'orders.cancel', 'orders.update_status',
            'cashier.view', 'cashier.open_register', 'cashier.settle', 'cashier.print_receipt', 'cashier.close_register', 'cashier.view_report',
            'purchases.view', 'purchases.create', 'purchases.edit', 'purchases.post', 'purchases.cancel',
            'stock.view', 'stock.export', 'transfers.view', 'transfers.create', 'transfers.send', 'transfers.receive', 'transfers.cancel',
            'audit_logs.view', 'audit_logs.export', 'profile.view', 'profile.edit', 'profile.change_password',
        ])->delete();
    }
};
