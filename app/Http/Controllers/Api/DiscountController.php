<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortCol = in_array($request->string('sort_col')->toString(), ['name', 'number'], true)
                    ? $request->string('sort_col')->toString()
                    : 'created_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $query = Discount::query()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
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
            'value' => ['required', 'numeric', 'min:0'],
            'type' => ['required', 'string', 'in:percentage,fixed'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);
        $payload['number'] = $this->nextNumber();

        return response()->json(Discount::create($payload), 201);
    }

    public function show(Discount $discount): JsonResponse
    {
        return response()->json($discount);
    }

    public function update(Request $request, Discount $discount): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'value' => ['required', 'numeric', 'min:0'],
            'type' => ['required', 'string', 'in:percentage,fixed'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $discount->update($payload);

        return response()->json($discount->refresh());
    }

    public function destroy(Discount $discount): JsonResponse
    {
        $discount->delete();

        return response()->json(['message' => 'Discount deleted.']);
    }

    private function nextNumber(): string
    {
        $model = Discount::class;
        $next = ($model::withTrashed()->max('id') ?? 0) + 1;

        return str_pad((string) $next, max(3, strlen((string) $next)), '0', STR_PAD_LEFT);
    }
}
