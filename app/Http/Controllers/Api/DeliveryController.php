<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Services\ApiImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'search' => 'nullable|string|max:120',
            'warehouse_id' => 'nullable|integer',
            'per_page' => 'nullable|integer',
        ]);

        $query = Delivery::query()->with(['warehouse']);

        if (!empty($payload['warehouse_id'])) {
            $wid = (int) $payload['warehouse_id'];
            $query->where(function ($q) use ($wid) {
                $q->whereNull('warehouse_id')
                  ->orWhere('warehouse_id', $wid);
            });
        }

        if (!empty($payload['search'])) {
            $s = trim($payload['search']);
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%");
            });
        }

        $query->orderBy('created_at', 'desc');

        $perPage = (int) ($payload['per_page'] ?? 20);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 20;

        $records = $query->paginate($perPage);
        return response()->json(['data' => $records]);
    }

    public function store(Request $request, ApiImageStorage $images): JsonResponse
    {
        $request->merge([
            'email' => $request->filled('email') ? trim((string) $request->input('email')) : null,
            'phone' => $request->filled('phone') ? trim((string) $request->input('phone')) : null,
            'note' => $request->filled('note') ? trim((string) $request->input('note')) : null,
            'warehouse_id' => $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null,
        ]);

        $payload = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:50',
            'note' => 'nullable|string',
            'warehouse_id' => 'nullable|integer|exists:locations,id',
            'is_active' => 'nullable|boolean',
            'image_base64' => 'nullable|string|max:' . $this->maxEncodedImageLength(),
        ]);

        $newImage = $images->storeBase64($payload['image_base64'] ?? null, 'deliveries');

        try {
            $delivery = DB::transaction(function () use ($payload, $newImage, $request) {
                return Delivery::query()->create([
                    'name' => trim($payload['name']),
                    'email' => !empty($payload['email']) ? trim($payload['email']) : null,
                    'phone' => !empty($payload['phone']) ? trim($payload['phone']) : null,
                    'note' => !empty($payload['note']) ? trim($payload['note']) : null,
                    'warehouse_id' => $payload['warehouse_id'] ?? null,
                    'is_active' => $payload['is_active'] ?? true,
                    'image_url' => $newImage,
                    'created_by' => $request->user()?->id,
                ]);
            });

            return response()->json(['data' => $delivery->load('warehouse')], 201);
        } catch (\Exception $e) {
            if ($newImage) {
                $images->delete($newImage, 'deliveries');
            }
            throw $e;
        }
    }

    public function update(Request $request, int $id, ApiImageStorage $images): JsonResponse
    {
        $delivery = Delivery::query()->findOrFail($id);

        $request->merge([
            'email' => $request->filled('email') ? trim((string) $request->input('email')) : null,
            'phone' => $request->filled('phone') ? trim((string) $request->input('phone')) : null,
            'note' => $request->filled('note') ? trim((string) $request->input('note')) : null,
            'warehouse_id' => $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null,
        ]);

        $payload = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:50',
            'note' => 'nullable|string',
            'warehouse_id' => 'nullable|integer|exists:locations,id',
            'is_active' => 'nullable|boolean',
            'image_base64' => 'nullable|string|max:' . $this->maxEncodedImageLength(),
        ]);

        $newImage = $images->storeBase64($payload['image_base64'] ?? null, 'deliveries');
        $oldImage = $delivery->image_url;

        try {
            DB::transaction(function () use ($delivery, $payload, $newImage, $request) {
                $delivery->update([
                    'name' => trim($payload['name']),
                    'email' => !empty($payload['email']) ? trim($payload['email']) : null,
                    'phone' => !empty($payload['phone']) ? trim($payload['phone']) : null,
                    'note' => !empty($payload['note']) ? trim($payload['note']) : null,
                    'warehouse_id' => array_key_exists('warehouse_id', $payload) ? $payload['warehouse_id'] : $delivery->warehouse_id,
                    'is_active' => $payload['is_active'] ?? true,
                    'image_url' => $newImage ?? $delivery->image_url,
                    'updated_by' => $request->user()?->id,
                ]);
            });

            if ($newImage && $oldImage) {
                $images->delete($oldImage, 'deliveries');
            }

            return response()->json(['data' => $delivery->fresh()->load('warehouse')]);
        } catch (\Exception $e) {
            if ($newImage) {
                $images->delete($newImage, 'deliveries');
            }
            throw $e;
        }
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $delivery = Delivery::query()->findOrFail($id);
        $delivery->update(['is_active' => !$delivery->is_active]);

        return response()->json(['data' => $delivery->fresh()]);
    }

    public function destroy(int $id, ApiImageStorage $images): JsonResponse
    {
        $delivery = Delivery::query()->findOrFail($id);
        $image = $delivery->image_url;
        $delivery->delete();

        if ($image) {
            $images->delete($image, 'deliveries');
        }

        return response()->json(['message' => 'Delivery deleted.']);
    }

    private function maxEncodedImageLength(): int
    {
        return (int) ceil(max(1, (int) config('pos.max_image_bytes', 5 * 1024 * 1024)) * 4 / 3) + 128;
    }
}
