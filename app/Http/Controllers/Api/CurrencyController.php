<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CurrencyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortCol = in_array($request->string('sort_col')->toString(), ['name', 'number'], true)
                    ? $request->string('sort_col')->toString()
                    : 'created_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $query = Currency::query()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('symbol', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortCol, $sortDir);

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 10;

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:10'],
            'symbol' => ['required', 'string', 'max:10'],
            'decimal_places' => ['required', 'integer', 'min:0', 'max:8'],
            'is_active' => ['boolean'],
            'is_major' => ['boolean'],
        ]);
        $payload['number'] = $this->nextNumber();

        if (($payload['is_major'] ?? false) === true) {
            DB::table('currencies')->update(['is_major' => false]);
        }

        return response()->json(Currency::create($payload), 201);
    }

    public function show(Currency $currency): JsonResponse
    {
        return response()->json($currency);
    }

    public function update(Request $request, Currency $currency): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:10'],
            'symbol' => ['required', 'string', 'max:10'],
            'decimal_places' => ['required', 'integer', 'min:0', 'max:8'],
            'is_active' => ['boolean'],
            'is_major' => ['boolean'],
        ]);

        if (($payload['is_major'] ?? false) === true) {
            DB::table('currencies')->where('id', '!=', $currency->id)->update(['is_major' => false]);
        }

        $currency->update($payload);

        return response()->json($currency->refresh());
    }

    public function destroy(Currency $currency): JsonResponse
    {
        if ($currency->is_major) {
            return response()->json(['message' => 'Set another main currency before deleting this currency.'], 422);
        }
        $currency->delete();

        return response()->json(['message' => 'Currency deleted.']);
    }

    private function nextNumber(): string
    {
        $model = Currency::class;
        $next = ($model::withTrashed()->max('id') ?? 0) + 1;

        return str_pad((string) $next, max(3, strlen((string) $next)), '0', STR_PAD_LEFT);
    }
}
