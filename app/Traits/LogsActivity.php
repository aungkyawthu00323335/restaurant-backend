<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

trait LogsActivity
{
    public static function bootLogsActivity()
    {
        static::created(function ($model) {
            static::logActivity($model, 'Created');
        });

        static::updated(function ($model) {
            static::logActivity($model, 'Updated');
        });

        static::deleted(function ($model) {
            static::logActivity($model, 'Deleted');
        });
    }

    protected static function logActivity($model, $actionType)
    {
        if (Auth::check()) {
            $className = class_basename($model);
            $recordId = $model->getKey();
            
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => "{$actionType} {$className} (ID: {$recordId})",
                'module' => $className,
                'outlet_id' => $model->getAttribute('outlet_id')
                    ?? $model->getAttribute('location_id')
                    ?? (App::has('current_outlet_id') ? App::make('current_outlet_id') : null),
                'reference_type' => $className,
                'reference_id' => $recordId,
                'reason' => static::requestReason(),
                'request_id' => app()->bound('request')
                    ? app('request')->attributes->get('request_id')
                    : null,
                'created_at' => now(),
            ]);
        }
    }

    private static function requestReason(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');
        if (! $request instanceof Request) {
            return null;
        }

        foreach (['reason', 'cancellation_reason', 'note'] as $field) {
            if ($request->filled($field)) {
                return (string) $request->input($field);
            }
        }

        return null;
    }
}
