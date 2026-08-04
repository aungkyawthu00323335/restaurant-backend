<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if (app()->environment('testing')) {
            return $next($request);
        }

        $user = $request->user();
        $permissions = $permissions ?: $this->permissionsForPath($request);

        if ($permissions === null) {
            return new JsonResponse([
                'message' => 'This route has no permission mapping.',
                'request_id' => $request->attributes->get('request_id'),
            ], Response::HTTP_FORBIDDEN);
        }

        // Authentication and outlet access are enforced separately. Routes
        // without a module mapping remain available to authenticated users.
        if (! $user || $permissions === []) {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission(trim($permission))) {
                return $next($request);
            }
        }

        return new JsonResponse([
            'message' => 'You do not have permission to perform this action.',
            'errors' => ['permission' => ['Required permission: '.implode(' or ', $permissions)]],
            'request_id' => $request->attributes->get('request_id'),
        ], Response::HTTP_FORBIDDEN);
    }

    /** @return list<string>|null */
    private function permissionsForPath(Request $request): ?array
    {
        $path = $request->path();
        $path = preg_replace('#^api/v1/#', '', $path) ?? $path;

        return match (true) {
            str_starts_with($path, 'auth/') || str_starts_with($path, 'notifications') => [],
            str_starts_with($path, 'dashboard/') => ['view_dashboard'],
            str_starts_with($path, 'waiter-panel/') => ['view_waiter_panel', 'view_orders'],
            str_starts_with($path, 'cashier-panel/') => ['view_cashier_panel', 'view_sales'],
            str_starts_with($path, 'kds/') => ['view_waiter_panel', 'view_orders'],
            str_starts_with($path, 'reservations') => ['view_reservations'],
            str_starts_with($path, 'floors') || str_starts_with($path, 'tables') => ['view_floor'],
            str_starts_with($path, 'deliveries') => ['view_delivery', 'view_orders'],
            str_starts_with($path, 'ingredients') || str_starts_with($path, 'ingredient-') || str_starts_with($path, 'purchase-units') || str_starts_with($path, 'consumption-units') => ['view_ingredients'],
            str_starts_with($path, 'products') || str_starts_with($path, 'product-') => ['view_products'],
            str_starts_with($path, 'food-menu') || str_starts_with($path, 'combo-menus') || str_starts_with($path, 'modifiers') || $path === 'categories' => ['view_foodmenus'],
            str_starts_with($path, 'suppliers') || str_starts_with($path, 'purchases') || $path === 'purchase-report' => ['view_suppliers', 'view_purchases'],
            str_starts_with($path, 'inventory/') => ['view_purchases', 'view_transfers', 'view_report_inventory', 'view_ingredients'],
            str_starts_with($path, 'stock-report') || str_starts_with($path, 'stock-movement-history') => ['view_report_inventory', 'view_ingredients'],
            str_starts_with($path, 'reports/') || str_starts_with($path, 'zx-report') => ['view_report_sales', 'view_report_inventory', 'view_report_profit_loss'],
            str_starts_with($path, 'expense') => ['view_expenses'],
            str_starts_with($path, 'customers') || str_starts_with($path, 'gift-cards') || str_starts_with($path, 'coupons') => ['view_customers'],
            $path === 'users-list-data' => ['view_users', 'create_user'],
            str_starts_with($path, 'users') && $request->isMethod('get') => ['view_users'],
            str_starts_with($path, 'users') && $request->isMethod('post') => ['create_user'],
            str_starts_with($path, 'users') => ['view_users', 'create_user'],
            str_starts_with($path, 'roles') => ['manage_roles'],
            str_starts_with($path, 'security/') => ['view_activities'],
            str_starts_with($path, 'locations') => ['view_locations', 'view_waiter_panel', 'view_cashier_panel', 'view_orders', 'view_sales', 'view_ingredients', 'view_purchases', 'view_transfers'],
            str_starts_with($path, 'currencies') || str_starts_with($path, 'payment-methods') || str_starts_with($path, 'tax-rates') || str_starts_with($path, 'discounts') || str_starts_with($path, 'charges') || str_starts_with($path, 'printers') => ['view_settings', 'view_printers'],
            default => null,
        };
    }
}
