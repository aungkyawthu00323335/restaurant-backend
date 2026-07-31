<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Floor;
use App\Models\Location;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FloorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'active_status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'sort_col' => ['nullable', 'string', Rule::in(['id', 'name', 'created_at'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
        ]);

        $sortCol = $payload['sort_col'] ?? 'created_at';
        $sortDir = $payload['sort_dir'] ?? 'desc';

        $query = Floor::query()->with('location:id,name');
        $this->applyFilters($query, $payload);
        $query->orderBy($sortCol, $sortDir)->orderBy('id', 'desc');

        $perPage = (int) ($payload['per_page'] ?? 20);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 20;

        $records = $query->paginate($perPage)->through(
            fn (Floor $floor): array => $this->resource($floor)
        );

        return response()->json(['data' => $records]);
    }

    public function createData(): JsonResponse
    {
        $outlets = Location::query()->where('is_active', true)->get(['id', 'name']);

        return response()->json([
            'data' => [
                'outlets' => $outlets,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $outlet = Location::query()->findOrFail($payload['location_id']);
        if (!$outlet->is_active) {
            abort(422, 'Selected Outlet is inactive.');
        }

        $name = trim($payload['name']);
        $code = strtoupper(trim($payload['code']));

        $this->checkNameUnique($name, $payload['location_id']);
        $this->checkCodeUnique($code, $payload['location_id']);

        $floor = DB::transaction(function () use ($payload, $name, $code, $request) {
            return Floor::query()->create([
                'location_id' => $payload['location_id'],
                'name' => $name,
                'code' => $code,
                'description' => $payload['description'] ?? null,
                'is_active' => $payload['is_active'] ?? true,
                'note' => $payload['note'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
        });

        $floor->load('location:id,name');

        return response()->json(['data' => $this->resource($floor)], 201);
    }

    public function show(int $id): JsonResponse
    {
        $floor = Floor::query()->with('location:id,name')->findOrFail($id);
        $resource = $this->resource($floor);

        $resource['updated_by'] = $floor->updatedBy?->name;
        $resource['created_by'] = $floor->createdBy?->name;
        $resource['created_at'] = $floor->created_at?->toIso8601String();
        $resource['updated_at'] = $floor->updated_at?->toIso8601String();

        $tables = RestaurantTable::query()
            ->where('floor_id', $floor->id)
            ->orderBy('table_no')
            ->get(['id', 'table_no', 'capacity', 'status', 'is_active', 'description']);

        $resource['tables'] = $tables->map(fn ($t) => [
            'id' => $t->id,
            'table_no' => $t->table_no,
            'capacity' => $t->capacity,
            'operational_status' => $t->status,
            'is_active' => $t->is_active,
            'description' => $t->description,
        ])->all();

        return response()->json(['data' => $resource]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $floor = Floor::query()->findOrFail($id);

        $payload = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Block outlet change if floor has history
        if ((int) $payload['location_id'] !== $floor->location_id) {
            $hasHistory = Order::query()->where('floor_id', $floor->id)->exists()
                || RestaurantTable::query()->where('floor_id', $floor->id)->exists()
                || Reservation::query()->where('floor_id', $floor->id)->exists();

            if ($hasHistory) {
                abort(422, 'This Floor cannot be moved to another Outlet because it already has Tables or transaction history.');
            }
        }

        $name = trim($payload['name']);
        $code = strtoupper(trim($payload['code']));

        $this->checkNameUnique($name, $payload['location_id'], $floor->id);
        $this->checkCodeUnique($code, $payload['location_id'], $floor->id);

        DB::transaction(function () use ($floor, $payload, $name, $code, $request) {
            $floor->update([
                'location_id' => $payload['location_id'],
                'name' => $name,
                'code' => $code,
                'description' => $payload['description'] ?? null,
                'is_active' => $payload['is_active'] ?? true,
                'note' => $payload['note'] ?? null,
                'updated_by' => $request->user()?->id,
            ]);
        });

        $floor->load('location:id,name');

        return response()->json(['data' => $this->resource($floor)]);
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $floor = Floor::query()->findOrFail($id);

        if ($floor->is_active) {
            $hasOccupiedOrReserved = RestaurantTable::query()
                ->where('floor_id', $floor->id)
                ->whereIn('status', ['occupied', 'reserved'])
                ->exists();

            if ($hasOccupiedOrReserved) {
                abort(422, 'This Floor has active or reserved Tables. Complete or move those Orders before deactivating the Floor.');
            }
        }

        $floor->update(['is_active' => !$floor->is_active]);

        return response()->json(['data' => $this->resource($floor->fresh()->load('location:id,name'))]);
    }

    public function destroy(int $id): JsonResponse
    {
        $floor = Floor::query()->findOrFail($id);

        $hasTables = RestaurantTable::query()->where('floor_id', $floor->id)->exists();
        $hasOrders = Order::query()->where('floor_id', $floor->id)->exists();
        $hasReservations = Reservation::query()->where('floor_id', $floor->id)->exists();

        if ($hasTables || $hasOrders || $hasReservations) {
            abort(422, 'This Floor cannot be deleted because it has Tables or transaction history. Please deactivate it instead.');
        }

        $floor->delete();

        return response()->json(['message' => 'Floor deleted.']);
    }

    private function applyFilters(Builder $query, array $payload): void
    {
        $query
            ->when(isset($payload['search']) && trim((string) $payload['search']) !== '', function (Builder $q) use ($payload): void {
                $s = trim((string) $payload['search']);
                $q->where(function (Builder $qq) use ($s): void {
                    $qq->where('name', 'like', "%{$s}%")
                        ->orWhere('code', 'like', "%{$s}%");
                });
            })
            ->when(isset($payload['location_id']), fn (Builder $q) => $q->where('location_id', $payload['location_id']))
            ->when(isset($payload['active_status']), fn (Builder $q) => $q->where('is_active', $payload['active_status'] === 'active'));
    }

    private function checkNameUnique(string $name, int $locationId, ?int $ignoreId = null): void
    {
        $exists = Floor::query()
            ->where('location_id', $locationId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($exists) {
            abort(422, 'Floor Name already exists in this Outlet.');
        }
    }

    private function checkCodeUnique(string $code, int $locationId, ?int $ignoreId = null): void
    {
        $exists = Floor::query()
            ->where('location_id', $locationId)
            ->whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower($code)])
            ->when($ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($exists) {
            abort(422, 'Floor Code already exists in this Outlet.');
        }
    }

    private function resource(Floor $floor): array
    {
        $tableCounts = $this->getTableCounts($floor->id);

        return [
            'id' => $floor->id,
            'location_id' => $floor->location_id,
            'outlet_name' => $floor->location?->name ?? '',
            'name' => $floor->name,
            'code' => $floor->code ?? '',
            'description' => $floor->description,
            'is_active' => $floor->is_active,
            'note' => $floor->note,
            'total_tables' => $tableCounts['total'],
            'available_tables' => $tableCounts['available'],
            'occupied_tables' => $tableCounts['occupied'],
            'reserved_tables' => $tableCounts['reserved'],
            'merged_tables' => $tableCounts['merged'],
            'inactive_tables' => $tableCounts['inactive'],
            'created_by' => $floor->createdBy?->name,
            'created_at' => $floor->created_at?->toIso8601String(),
        ];
    }

    private function getTableCounts(int $floorId): array
    {
        $counts = RestaurantTable::query()
            ->where('floor_id', $floorId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'available' AND is_active = true THEN 1 ELSE 0 END) as available")
            ->selectRaw("SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied")
            ->selectRaw("SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) as reserved")
            ->selectRaw("SUM(CASE WHEN status = 'merged' THEN 1 ELSE 0 END) as merged")
            ->selectRaw("SUM(CASE WHEN is_active = false THEN 1 ELSE 0 END) as inactive")
            ->first();

        return [
            'total' => (int) ($counts->total ?? 0),
            'available' => (int) ($counts->available ?? 0),
            'occupied' => (int) ($counts->occupied ?? 0),
            'reserved' => (int) ($counts->reserved ?? 0),
            'merged' => (int) ($counts->merged ?? 0),
            'inactive' => (int) ($counts->inactive ?? 0),
        ];
    }
}
