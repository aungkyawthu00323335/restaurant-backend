<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseUnit;
use App\Services\ApiImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class PurchaseUnitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortCol = in_array($request->string('sort_col')->toString(), ['name'], true)
            ? $request->string('sort_col')->toString()
            : 'created_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $query = PurchaseUnit::query()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortCol, $sortDir);

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = ($perPage > 0 && $perPage <= 5000) ? $perPage : 10;

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request, ApiImageStorage $images): JsonResponse
    {
        $maxEncodedLength = $this->maxEncodedImageLength();
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('purchase_units', 'name')],
            'description' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'image_base64' => ['nullable', 'string', 'max:'.$maxEncodedLength],
            'image_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $newImage = $images->storeBase64($payload['image_base64'] ?? null, 'purchase-units');
        $payload['image_url'] = $newImage ?? ($payload['image_url'] ?? null);
        unset($payload['image_base64'], $payload['image_name']);

        try {
            $unit = PurchaseUnit::create($payload);
        } catch (Throwable $exception) {
            if ($newImage !== null) {
                $images->delete($newImage, 'purchase-units');
            }
            throw $exception;
        }

        return response()->json($unit, 201);
    }

    public function show(PurchaseUnit $purchaseUnit): JsonResponse
    {
        return response()->json($purchaseUnit);
    }

    public function update(Request $request, PurchaseUnit $purchaseUnit, ApiImageStorage $images): JsonResponse
    {
        $maxEncodedLength = $this->maxEncodedImageLength();
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('purchase_units', 'name')->ignore($purchaseUnit->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'image_base64' => ['nullable', 'string', 'max:'.$maxEncodedLength],
            'image_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $oldImage = $purchaseUnit->image_url;
        $newImage = $images->storeBase64($payload['image_base64'] ?? null, 'purchase-units');
        $payload['image_url'] = $newImage ?? (array_key_exists('image_url', $payload) ? $payload['image_url'] : $oldImage);
        unset($payload['image_base64'], $payload['image_name']);

        try {
            $purchaseUnit->update($payload);
        } catch (Throwable $exception) {
            if ($newImage !== null) {
                $images->delete($newImage, 'purchase-units');
            }
            throw $exception;
        }

        if ($oldImage !== $payload['image_url']) {
            $images->delete($oldImage, 'purchase-units');
        }

        return response()->json($purchaseUnit->refresh());
    }

    public function destroy(PurchaseUnit $purchaseUnit, ApiImageStorage $images): JsonResponse
    {
        $image = $purchaseUnit->image_url;
        $purchaseUnit->delete();
        $images->delete($image, 'purchase-units');

        return response()->json(['message' => 'Purchase unit deleted.']);
    }

    private function maxEncodedImageLength(): int
    {
        return (int) ceil(max(1, (int) config('pos.max_image_bytes', 5 * 1024 * 1024)) * 4 / 3) + 128;
    }
}
