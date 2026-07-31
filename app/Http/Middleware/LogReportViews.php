<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class LogReportViews
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && $request->isMethod('get')) {
            $path = $request->path();
            
            // Map paths to readable report names
            $reportName = 'Report';
            if (str_contains($path, 'reports/sale')) $reportName = 'Sale Report';
            elseif (str_contains($path, 'reports/register')) $reportName = 'Register Report';
            elseif (str_contains($path, 'reports/zx')) $reportName = 'Z/X Report';
            elseif (str_contains($path, 'reports/profit-loss')) $reportName = 'Profit & Loss Report';
            elseif (str_contains($path, 'reports/item-sales')) $reportName = 'Item Sales Report';
            elseif (str_contains($path, 'reports/sales-by-category')) $reportName = 'Sales By Category Report';
            elseif (str_contains($path, 'reports/tax')) $reportName = 'Tax Report';

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => "Viewed {$reportName}",
                'module' => 'Report',
                'created_at' => now(),
            ]);
        }
        
        return $next($request);
    }
}
