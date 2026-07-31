<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Services\ApiImageStorage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 10);
        $search = $request->input('search');
        $categoryId = $request->input('category_id');
        $outletId = $request->input('outlet_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = Expense::with(['category', 'outlet']);

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('note', 'like', "%{$search}%");
            });
        }

        if ($categoryId) {
            $query->where('expense_category_id', $categoryId);
        }

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        return new JsonResponse($query->latest('date')->latest('id')->paginate($perPage));
    }

    public function store(Request $request, ApiImageStorage $images): JsonResponse
    {
        $validated = $request->validate([
            'expense_category_id' => 'required|exists:expense_categories,id',
            'outlet_id' => 'nullable|exists:locations,id',
            'date' => 'required|date',
            'amount' => 'required|numeric|min:0',
            'reference_no' => 'nullable|string|max:255',
            'note' => 'nullable|string',
            'image_base64' => 'nullable|string',
        ]);

        $attachment = $images->storeBase64($validated['image_base64'] ?? null, 'expenses');
        
        $expense = Expense::create([
            'expense_category_id' => $validated['expense_category_id'],
            'outlet_id' => $validated['outlet_id'],
            'date' => $validated['date'],
            'amount' => $validated['amount'],
            'reference_no' => $validated['reference_no'],
            'note' => $validated['note'],
            'attachment' => $attachment,
        ]);

        $expense->load(['category', 'outlet']);

        return new JsonResponse([
            'message' => 'Expense created successfully',
            'data' => $expense,
        ], Response::HTTP_CREATED);
    }

    public function show(Expense $expense): JsonResponse
    {
        $expense->load(['category', 'outlet']);
        return new JsonResponse($expense);
    }

    public function update(Request $request, Expense $expense, ApiImageStorage $images): JsonResponse
    {
        $validated = $request->validate([
            'expense_category_id' => 'required|exists:expense_categories,id',
            'outlet_id' => 'nullable|exists:locations,id',
            'date' => 'required|date',
            'amount' => 'required|numeric|min:0',
            'reference_no' => 'nullable|string|max:255',
            'note' => 'nullable|string',
            'image_base64' => 'nullable|string',
        ]);

        $attachment = $images->storeBase64($validated['image_base64'] ?? null, 'expenses');
        
        $updateData = [
            'expense_category_id' => $validated['expense_category_id'],
            'outlet_id' => $validated['outlet_id'],
            'date' => $validated['date'],
            'amount' => $validated['amount'],
            'reference_no' => $validated['reference_no'],
            'note' => $validated['note'],
        ];

        if ($attachment) {
            $updateData['attachment'] = $attachment;
        }

        $expense->update($updateData);

        $expense->load(['category', 'outlet']);

        return new JsonResponse([
            'message' => 'Expense updated successfully',
            'data' => $expense,
        ]);
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $expense->delete();

        return new JsonResponse([
            'message' => 'Expense deleted successfully'
        ]);
    }

    public function formData(): JsonResponse
    {
        return new JsonResponse([
            'categories' => ExpenseCategory::where('status', 'active')->get(['id', 'name']),
            'locations' => Location::where('is_active', true)->get(['id', 'name']),
        ]);
    }
}
