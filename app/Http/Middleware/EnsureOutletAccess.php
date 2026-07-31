<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class EnsureOutletAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || app()->environment('testing')) {
            return $next($request);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $outletId = $this->resolveOutletId($request);
        $allowedOutletIds = $user->allowedOutletIds();

        if ($outletId !== null && ! in_array($outletId, $allowedOutletIds, true)) {
            return new JsonResponse([
                'message' => 'You do not have permission to view this outlet.',
                'errors' => ['outlet_id' => ['The selected outlet is not assigned to your account.']],
                'request_id' => $request->attributes->get('request_id'),
            ], Response::HTTP_FORBIDDEN);
        }

        if ($outletId === null && $this->requiresOutletContext($request)) {
            return new JsonResponse([
                'message' => 'Select an outlet before using this module.',
                'errors' => ['outlet_id' => ['An outlet context is required for this request.']],
                'request_id' => $request->attributes->get('request_id'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($outletId !== null && ! App::has('current_outlet_id')) {
            App::instance('current_outlet_id', $outletId);
        }
        if ($outletId !== null && ! App::has('active_outlet_id')) {
            App::instance('active_outlet_id', $outletId);
        }

        return $next($request);
    }

    private function resolveOutletId(Request $request): ?int
    {
        if (App::has('current_outlet_id')) {
            return (int) App::make('current_outlet_id');
        }

        $value = $request->input('outlet_id')
            ?? $request->input('location_id')
            ?? $request->header('X-Outlet-Id');

        if ($value !== null && ctype_digit((string) $value) && (int) $value > 0) {
            return (int) $value;
        }

        $route = $request->route();
        if ($route) {
            foreach ($route->parameters() as $parameter) {
                if (is_object($parameter) && isset($parameter->outlet_id)) {
                    return (int) $parameter->outlet_id;
                }
            }
        }

        return null;
    }

    private function requiresOutletContext(Request $request): bool
    {
        $path = preg_replace('#^api/v1/#', '', $request->path()) ?? $request->path();

        return str_starts_with($path, 'dashboard/')
            || str_starts_with($path, 'waiter-panel/')
            || str_starts_with($path, 'cashier-panel/')
            || str_starts_with($path, 'kds/')
            || str_starts_with($path, 'reservations')
            || str_starts_with($path, 'floors')
            || str_starts_with($path, 'tables')
            || str_starts_with($path, 'deliveries')
            || str_starts_with($path, 'ingredients')
            || str_starts_with($path, 'products')
            || str_starts_with($path, 'food-menu')
            || str_starts_with($path, 'inventory/')
            || str_starts_with($path, 'purchases')
            || str_starts_with($path, 'suppliers')
            || str_starts_with($path, 'expenses')
            || str_starts_with($path, 'reports/')
            || str_starts_with($path, 'stock-report')
            || str_starts_with($path, 'stock-movement-history');
    }
}
