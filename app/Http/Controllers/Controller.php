<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function boundedPageSize(Request $request, int $default = 15, ?int $max = null): int
    {
        $max ??= max(1, (int) config('pos.max_page_size', 100));

        return min($max, max(1, $request->integer('per_page', $default)));
    }

    protected function reportPageSize(Request $request, int $default = 15): int
    {
        $max = $request->attributes->get('report_export')
            ? max(1, (int) config('pos.max_export_rows', 5000))
            : max(1, (int) config('pos.max_page_size', 100));

        return min($max, max(1, $request->integer('per_page', $default)));
    }

    protected function prepareReportExport(Request $request): void
    {
        $request->attributes->set('report_export', true);
        $request->merge([
            'page' => 1,
            'per_page' => max(1, (int) config('pos.max_export_rows', 5000)),
        ]);
    }

    protected function enforceExportLimit(Builder $query): void
    {
        $limit = max(1, (int) config('pos.max_export_rows', 5000));

        if ((clone $query)->limit($limit + 1)->count() > $limit) {
            abort(422, "This export exceeds the {$limit}-row limit. Narrow the filters and try again.");
        }
    }
}
