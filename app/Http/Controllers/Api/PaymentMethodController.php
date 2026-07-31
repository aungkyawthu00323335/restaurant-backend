<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortCol = in_array($request->string('sort_col')->toString(), ['name', 'number'], true)
                    ? $request->string('sort_col')->toString()
                    : 'created_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $query = PaymentMethod::query()
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
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ]);
        $payload['number'] = $this->nextNumber();

        return response()->json(PaymentMethod::create($payload), 201);
    }

    public function show(PaymentMethod $paymentMethod): JsonResponse
    {
        return response()->json($paymentMethod);
    }

    public function update(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ]);

        $paymentMethod->update($payload);

        return response()->json($paymentMethod->refresh());
    }

    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        $paymentMethod->delete();

        return response()->json(['message' => 'Payment method deleted.']);
    }

    private function nextNumber(): string
    {
        $model = PaymentMethod::class;
        $next = ($model::withTrashed()->max('id') ?? 0) + 1;

        return str_pad((string) $next, max(3, strlen((string) $next)), '0', STR_PAD_LEFT);
    }
}
