<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\LoginHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityLogController extends Controller
{
    /**
     * Get login history list.
     */
    public function loginHistory(Request $request): JsonResponse
    {
        $search = $request->input('search');
        $status = $request->input('status');
        $perPage = (int) $request->input('per_page', 15);

        $query = LoginHistory::with('user');

        if ($search) {
            $query->whereHas('user', function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        $history = $query->orderBy('id', 'desc')->paginate($perPage);

        return new JsonResponse($history);
    }

    /**
     * Get security activity log list.
     */
    public function activityLogs(Request $request): JsonResponse
    {
        $search = $request->input('search');
        $module = $request->input('module');
        $perPage = (int) $request->input('per_page', 15);

        $query = ActivityLog::with('user');

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                  ->orWhereHas('user', function($userQ) use ($search) {
                      $userQ->where('name', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%");
                  });
            });
        }

        if ($module) {
            $query->where('module', $module);
        }

        $logs = $query->orderBy('id', 'desc')->paginate($perPage);

        return new JsonResponse($logs);
    }
}
