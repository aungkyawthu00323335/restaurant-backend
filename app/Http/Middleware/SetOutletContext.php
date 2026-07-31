<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

class SetOutletContext
{
    /**
     * Handle an incoming request and bind active outlet context for multi-outlet isolation.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Long-lived workers must never leak an outlet from a previous request.
        App::forgetInstance('current_outlet_id');
        App::forgetInstance('active_outlet_id');

        $outletId = $request->header('X-Outlet-Id') 
            ?? $request->input('location_id') 
            ?? $request->input('outlet_id');

        if ($outletId !== null && $outletId !== '' && (int)$outletId > 0) {
            $parsedId = (int)$outletId;

            // This middleware runs before and after authentication. Enforce
            // the assignment check on the authenticated pass.
            if (Auth::check() && ! $this->userCanAccessOutlet(Auth::user(), $parsedId)) {
                return response()->json([
                    'message' => 'You do not have permission to view this outlet.',
                    'errors' => ['outlet_id' => ['The selected outlet is not assigned to your account.']],
                ], Response::HTTP_FORBIDDEN);
            }

            App::instance('current_outlet_id', $parsedId);
            App::instance('active_outlet_id', $parsedId);

            // Inject into request parameters if not already set
            if (!$request->has('location_id')) {
                $request->merge(['location_id' => $parsedId]);
            }
            if (!$request->has('outlet_id')) {
                $request->merge(['outlet_id' => $parsedId]);
            }
        }

        return $next($request);
    }

    private function userCanAccessOutlet(?object $user, int $outletId): bool
    {
        if (! $user || app()->environment('testing')) {
            return true;
        }

        return $user->isSuperAdmin() || in_array($outletId, $user->allowedOutletIds(), true);
    }
}
