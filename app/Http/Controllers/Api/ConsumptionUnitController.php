<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsumptionUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ConsumptionUnitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortCol = in_array($request->string('sort_col')->toString(), ['name'], true)
            ? $request->string('sort_col')->toString()
            : 'created_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $query = ConsumptionUnit::query()
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortCol, $sortDir);

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = ($perPage > 0 && $perPage <= (int) config('pos.max_page_size', 100)) ? $perPage : 10;

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('consumption_units', 'name')],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ]);

        return response()->json(ConsumptionUnit::create($payload), 201);
    }

    public function show(ConsumptionUnit $consumptionUnit): JsonResponse
    {
        return response()->json($consumptionUnit);
    }

    public function update(Request $request, ConsumptionUnit $consumptionUnit): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('consumption_units', 'name')->ignore($consumptionUnit->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ]);

        $consumptionUnit->update($payload);

        return response()->json($consumptionUnit->refresh());
    }

    public function destroy(ConsumptionUnit $consumptionUnit): JsonResponse
    {
        if ($consumptionUnit->foodMenus()->exists() || $consumptionUnit->foodMenuIngredients()->exists()) {
            return response()->json([
                'message' => 'This unit is used by a food menu and cannot be deleted.',
            ], Response::HTTP_CONFLICT);
        }

        $consumptionUnit->delete();

        return response()->json(['message' => 'Consumption unit deleted.']);
    }
}
