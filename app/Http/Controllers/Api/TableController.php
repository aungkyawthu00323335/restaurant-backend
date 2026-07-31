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

class TableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'floor_id' => ['nullable', 'integer', 'exists:floors,id'],
            'operational_status' => ['nullable', 'string', Rule::in(['available', 'occupied', 'reserved', 'merged', 'inactive'])],
            'active_status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'sort_col' => ['nullable', 'string', Rule::in(['table_no', 'capacity', 'created_at'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
        ]);

        $sortCol = $payload['sort_col'] ?? 'created_at';
        $sortDir = $payload['sort_dir'] ?? 'asc';

        $query = RestaurantTable::query()
            ->with(['floor:id,name,location_id', 'floor.location:id,name'])
            ->withCount([
                'orders as active_order_count' => fn ($q) => $q->whereNotIn('order_status', ['completed', 'cancelled']),
            ]);
        $this->applyFilters($query, $payload);
        $query->orderBy($sortCol, $sortDir)->orderBy('id', 'desc');

        $perPage = (int) ($payload['per_page'] ?? 20);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 20;

        $records = $query->paginate($perPage)->through(
            fn (RestaurantTable $table): array => $this->resource($table)
        );

        return response()->json(['data' => $records]);
    }

    public function createData(): JsonResponse
    {
        $outlets = Location::query()->where('is_active', true)->get(['id', 'name']);
        $floors = Floor::query()->where('is_active', true)->with('location:id,name')->get(['id', 'name', 'location_id']);

        return response()->json([
            'data' => [
                'outlets' => $outlets,
                'floors' => $floors,
                'shape_options' => ['Square', 'Rectangle', 'Round', 'Other'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'outlet_id' => ['required', 'integer', 'exists:locations,id'],
            'floor_id' => ['required', 'integer', 'exists:floors,id'],
            'table_no' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:50'],
            'capacity' => ['required', 'integer', 'min:1'],
            'shape' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Validate floor belongs to outlet
        $floor = Floor::query()->findOrFail($payload['floor_id']);
        if (!$floor->is_active) {
            abort(422, 'Inactive Floor cannot be selected.');
        }
        if ((int) $floor->location_id !== (int) $payload['outlet_id']) {
            abort(422, 'Selected Floor does not belong to the selected Outlet.');
        }

        $tableNo = trim($payload['table_no']);
        $code = !empty($payload['code']) ? strtoupper(trim($payload['code'])) : null;

        $this->checkTableNoUnique($tableNo, $payload['floor_id']);
        if ($code) {
            $this->checkCodeUnique($code, $payload['outlet_id']);
        }

        $table = DB::transaction(function () use ($payload, $tableNo, $code, $request) {
            return RestaurantTable::query()->create([
                'outlet_id' => $payload['outlet_id'],
                'floor_id' => $payload['floor_id'],
                'table_no' => $tableNo,
                'code' => $code,
                'capacity' => $payload['capacity'],
                'shape' => $payload['shape'] ?? null,
                'status' => 'available',
                'is_active' => $payload['is_active'] ?? true,
                'description' => $payload['description'] ?? null,
                'note' => $payload['note'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
        });

        $table->load(['floor:id,name,location_id', 'floor.location:id,name']);

        return response()->json(['data' => $this->resource($table)], 201);
    }

    public function show(int $id): JsonResponse
    {
        $table = RestaurantTable::query()
            ->with(['floor:id,name,location_id', 'floor.location:id,name', 'mergedWith:id,table_no'])
            ->findOrFail($id);

        $resource = $this->resource($table);
        $resource['created_by'] = $table->createdBy?->name;
        $resource['updated_by'] = $table->updatedBy?->name;
        $resource['created_at'] = $table->created_at?->toIso8601String();
        $resource['updated_at'] = $table->updated_at?->toIso8601String();
        $resource['shape'] = $table->shape;
        $resource['note'] = $table->note;
        $resource['merged_with_table_no'] = $table->mergedWith?->table_no;

        // Current active order
        $currentOrder = Order::query()
            ->where('table_id', $table->id)
            ->whereNotIn('order_status', ['completed', 'cancelled'])
            ->with('createdBy:id,name')
            ->first();

        if ($currentOrder) {
            $resource['current_order'] = [
                'id' => $currentOrder->id,
                'order_no' => $currentOrder->order_no,
                'pax' => $currentOrder->pax,
                'grand_total' => (float) $currentOrder->grand_total,
                'order_status' => $currentOrder->order_status,
                'created_by' => $currentOrder->createdBy?->name,
                'created_at' => $currentOrder->created_at?->toIso8601String(),
            ];
        }

        // Current reservation
        $currentReservation = Reservation::query()
            ->where('table_id', $table->id)
            ->whereIn('status', ['confirmed', 'seated'])
            ->first();

        if ($currentReservation) {
            $resource['current_reservation'] = [
                'id' => $currentReservation->id,
                'customer_name' => $currentReservation->customer_name,
                'customer_phone' => $currentReservation->customer_phone,
                'guest_count' => $currentReservation->guest_count,
                'checkin_time' => $currentReservation->checkin_time?->format('H:i'),
                'status' => $currentReservation->status,
            ];
        }

        return response()->json(['data' => $resource]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $table = RestaurantTable::query()->findOrFail($id);

        // Block change if table has active order or reservation
        $hasActiveOrder = Order::query()
            ->where('table_id', $table->id)
            ->whereNotIn('order_status', ['completed', 'cancelled'])
            ->exists();

        $hasActiveReservation = Reservation::query()
            ->where('table_id', $table->id)
            ->whereIn('status', ['confirmed', 'seated'])
            ->exists();

        $hasHistory = Order::query()->where('table_id', $table->id)->exists()
            || Reservation::query()->where('table_id', $table->id)->exists();

        $payload = $request->validate([
            'outlet_id' => ['required', 'integer', 'exists:locations,id'],
            'floor_id' => ['required', 'integer', 'exists:floors,id'],
            'table_no' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:50'],
            'capacity' => ['required', 'integer', 'min:1'],
            'shape' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Block outlet/floor change if history exists
        if ($hasHistory && (
            (int) $payload['outlet_id'] !== $table->outlet_id
            || (int) $payload['floor_id'] !== $table->floor_id
        )) {
            abort(422, 'This Table cannot be moved because it has Order or Reservation history.');
        }

        // Validate floor belongs to outlet
        $floor = Floor::query()->findOrFail($payload['floor_id']);
        if ((int) $floor->location_id !== (int) $payload['outlet_id']) {
            abort(422, 'Selected Floor does not belong to the selected Outlet.');
        }

        $tableNo = trim($payload['table_no']);
        $code = !empty($payload['code']) ? strtoupper(trim($payload['code'])) : null;

        $this->checkTableNoUnique($tableNo, $payload['floor_id'], $table->id);
        if ($code) {
            $this->checkCodeUnique($code, $payload['outlet_id'], $table->id);
        }

        DB::transaction(function () use ($table, $payload, $tableNo, $code, $request) {
            $table->update([
                'outlet_id' => $payload['outlet_id'],
                'floor_id' => $payload['floor_id'],
                'table_no' => $tableNo,
                'code' => $code,
                'capacity' => $payload['capacity'],
                'shape' => $payload['shape'] ?? null,
                'is_active' => $payload['is_active'] ?? true,
                'description' => $payload['description'] ?? null,
                'note' => $payload['note'] ?? null,
                'updated_by' => $request->user()?->id,
            ]);
        });

        $table->load(['floor:id,name,location_id', 'floor.location:id,name']);

        return response()->json(['data' => $this->resource($table)]);
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $table = RestaurantTable::query()->findOrFail($id);

        if ($table->is_active) {
            if (in_array($table->status, ['occupied', 'reserved', 'merged'])) {
                abort(422, 'This Table cannot be deactivated while it is Occupied, Reserved, or Merged.');
            }
        }

        $table->update(['is_active' => !$table->is_active, 'updated_by' => request()->user()?->id]);

        return response()->json(['data' => $this->resource($table->fresh()->load(['floor:id,name,location_id', 'floor.location:id,name']))]);
    }

    public function destroy(int $id): JsonResponse
    {
        $table = RestaurantTable::query()->withTrashed()->findOrFail($id);

        if ($table->trashed()) {
            abort(422, 'Table is already deleted.');
        }

        $hasOrders = Order::query()->where('table_id', $table->id)->exists();
        $hasReservations = Reservation::query()->where('table_id', $table->id)->exists();

        if ($hasOrders || $hasReservations || $table->merged_with_table_id) {
            abort(422, 'This Table cannot be permanently deleted because it has transaction history. Please deactivate it instead.');
        }

        $table->delete();

        return response()->json(['message' => 'Table deleted.']);
    }

    private function applyFilters(Builder $query, array $payload): void
    {
        $query
            ->when(isset($payload['search']) && trim((string) $payload['search']) !== '', function (Builder $q) use ($payload): void {
                $s = trim((string) $payload['search']);
                $q->where(function (Builder $qq) use ($s): void {
                    $qq->where('table_no', 'like', "%{$s}%")
                        ->orWhere('code', 'like', "%{$s}%");
                });
            })
            ->when(isset($payload['location_id']), fn (Builder $q) => $q->where('outlet_id', $payload['location_id']))
            ->when(isset($payload['floor_id']), fn (Builder $q) => $q->where('floor_id', $payload['floor_id']))
            ->when(isset($payload['operational_status']), fn (Builder $q) => $q->where('status', $payload['operational_status']))
            ->when(isset($payload['active_status']), fn (Builder $q) => $q->where('is_active', $payload['active_status'] === 'active'));
    }

    private function checkTableNoUnique(string $tableNo, int $floorId, ?int $ignoreId = null): void
    {
        $exists = RestaurantTable::query()
            ->where('floor_id', $floorId)
            ->whereRaw('LOWER(TRIM(table_no)) = ?', [mb_strtolower($tableNo)])
            ->when($ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($exists) {
            abort(422, 'Table Name already exists on this Floor.');
        }
    }

    private function checkCodeUnique(string $code, int $outletId, ?int $ignoreId = null): void
    {
        $exists = RestaurantTable::query()
            ->where('outlet_id', $outletId)
            ->whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower($code)])
            ->when($ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($exists) {
            abort(422, 'Table Code already exists in this Outlet.');
        }
    }

    private function resource(RestaurantTable $table): array
    {
        $currentOrderNo = null;
        $currentReservationInfo = null;

        $activeOrder = Order::query()
            ->where('table_id', $table->id)
            ->whereNotIn('order_status', ['completed', 'cancelled'])
            ->first(['order_no']);

        if ($activeOrder) {
            $currentOrderNo = $activeOrder->order_no;
        }

        $activeReservation = Reservation::query()
            ->where('table_id', $table->id)
            ->whereIn('status', ['confirmed', 'seated'])
            ->first(['customer_name']);

        if ($activeReservation) {
            $currentReservationInfo = $activeReservation->customer_name;
        }

        return [
            'id' => $table->id,
            'outlet_id' => $table->outlet_id,
            'outlet_name' => $table->floor?->location?->name ?? '',
            'floor_id' => $table->floor_id,
            'floor_name' => $table->floor?->name ?? '',
            'table_no' => $table->table_no,
            'code' => $table->code ?? '',
            'capacity' => $table->capacity,
            'shape' => $table->shape,
            'operational_status' => $table->status,
            'is_active' => $table->is_active,
            'description' => $table->description,
            'note' => $table->note,
            'current_order_no' => $currentOrderNo,
            'current_reservation' => $currentReservationInfo,
            'merged_with_table_id' => $table->merged_with_table_id,
            'active_order_count' => (int) ($table->active_order_count ?? 0),
            'created_by' => $table->createdBy?->name,
            'created_at' => $table->created_at?->toIso8601String(),
        ];
    }
}
