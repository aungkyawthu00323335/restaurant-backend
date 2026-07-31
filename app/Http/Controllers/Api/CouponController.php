<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortCol = in_array($request->string('sort_col')->toString(), ['name', 'number'], true)
                    ? $request->string('sort_col')->toString()
                    : 'created_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $query = Coupon::query()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('code', 'like', "%{$search}%")
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
            'code' => ['required', 'string', 'max:60', 'unique:coupons,code'],
            'description' => ['nullable', 'string'],
            'value' => ['required', 'numeric', 'min:0'],
            'type' => ['required', 'string', 'in:percentage,fixed'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after_or_equal:valid_from'],
            'min_order_amount' => ['required', 'numeric', 'min:0'],
            'max_usage_per_customer' => ['required', 'integer', 'min:1'],
            'total_usage_limit' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
        $payload['number'] = $this->nextNumber();

        return response()->json(Coupon::create($payload), 201);
    }

    public function show(Coupon $coupon): JsonResponse
    {
        return response()->json($coupon);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $payload = $request->validate([
            'code' => ['required', 'string', 'max:60', 'unique:coupons,code,'.$coupon->id],
            'description' => ['nullable', 'string'],
            'value' => ['required', 'numeric', 'min:0'],
            'type' => ['required', 'string', 'in:percentage,fixed'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after_or_equal:valid_from'],
            'min_order_amount' => ['required', 'numeric', 'min:0'],
            'max_usage_per_customer' => ['required', 'integer', 'min:1'],
            'total_usage_limit' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $coupon->update($payload);

        return response()->json($coupon->refresh());
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        $coupon->delete();

        return response()->json(['message' => 'Coupon deleted.']);
    }

    private function nextNumber(): string
    {
        $model = Coupon::class;
        $next = ($model::withTrashed()->max('id') ?? 0) + 1;

        return str_pad((string) $next, max(3, strlen((string) $next)), '0', STR_PAD_LEFT);
    }
}
