<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = $this->boundedPageSize($request, 10);
        $search = $request->input('search');

        $query = ExpenseCategory::query();

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        return new JsonResponse($query->latest()->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
        ]);

        $category = ExpenseCategory::create($validated);

        return new JsonResponse([
            'message' => 'Expense category created successfully',
            'data' => $category,
        ], Response::HTTP_CREATED);
    }

    public function show(ExpenseCategory $expenseCategory): JsonResponse
    {
        return new JsonResponse($expenseCategory);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
        ]);

        $expenseCategory->update($validated);

        return new JsonResponse([
            'message' => 'Expense category updated successfully',
            'data' => $expenseCategory,
        ]);
    }

    public function destroy(ExpenseCategory $expenseCategory): JsonResponse
    {
        // Add check if category is used by expenses
        if ($expenseCategory->expenses()->count() > 0) {
            return new JsonResponse([
                'message' => 'Cannot delete category because it has related expenses.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $expenseCategory->delete();

        return new JsonResponse([
            'message' => 'Expense category deleted successfully'
        ]);
    }
}
