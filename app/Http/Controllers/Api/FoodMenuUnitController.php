<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FoodMenuUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class FoodMenuUnitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortCol = in_array($request->string('sort_col')->toString(), ['name'], true)
            ? $request->string('sort_col')->toString()
            : 'created_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $query = FoodMenuUnit::query()
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
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 10;

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('food_menu_units', 'name')],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ]);

        return response()->json(FoodMenuUnit::create($payload), 201);
    }

    public function show(FoodMenuUnit $foodMenuUnit): JsonResponse
    {
        return response()->json($foodMenuUnit);
    }

    public function update(Request $request, FoodMenuUnit $foodMenuUnit): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('food_menu_units', 'name')->ignore($foodMenuUnit->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ]);

        $foodMenuUnit->update($payload);

        return response()->json($foodMenuUnit->refresh());
    }

    public function destroy(FoodMenuUnit $foodMenuUnit): JsonResponse
    {
        if ($foodMenuUnit->foodMenus()->exists()) {
            return response()->json([
                'message' => 'This unit is used by a food menu and cannot be deleted.',
            ], Response::HTTP_CONFLICT);
        }

        $foodMenuUnit->delete();

        return response()->json(['message' => 'Food menu unit deleted.']);
    }
}
