<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Floor;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class ReservationController extends Controller
{
    private const DEFAULT_DURATION_MINUTES = 90;
    private const PREPARATION_WINDOW_MINUTES = 30;

    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', 'string', Rule::in(['pending', 'confirmed', 'arrived', 'seated', 'cancelled', 'completed', 'no_show'])],
            'customer' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Reservation::query()->with(['outlet:id,name', 'floor:id,name', 'table:id,table_no,code']);

        if (!empty($payload['date_from'])) {
            $query->whereDate('reservation_date', '>=', $payload['date_from']);
        }
        if (!empty($payload['date_to'])) {
            $query->whereDate('reservation_date', '<=', $payload['date_to']);
        }
        if (!empty($payload['status']) && $payload['status'] !== 'all') {
            $query->where('status', $payload['status']);
        }
        if (!empty($payload['customer'])) {
            $query->where(function ($customerQuery) use ($payload): void {
                $customerQuery->where('customer_name', 'like', '%' . $payload['customer'] . '%')
                    ->orWhere('customer_phone', 'like', '%' . $payload['customer'] . '%');
            });
        }

        $query->orderBy('reservation_date', 'desc')->orderBy('checkin_time', 'desc');

        $perPage = min(100, max(1, (int) ($payload['per_page'] ?? 10)));
        $records = $query->paginate($perPage);

        return response()->json($records);
    }

    public function availableTables(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'outlet_id' => ['required', 'integer', 'exists:locations,id'],
            'floor_id' => ['required', 'integer', 'exists:floors,id'],
            'reservation_date' => ['required', 'date', 'after_or_equal:today'],
            'checkin_time' => ['required', 'date_format:H:i'],
            'guest_count' => ['required', 'integer', 'min:1'],
            'ignore_id' => ['nullable', 'integer', 'exists:reservations,id'],
        ]);

        $this->validateReservationTime($payload['reservation_date'], $payload['checkin_time']);

        $tables = RestaurantTable::query()
            ->where('outlet_id', $payload['outlet_id'])
            ->where('floor_id', $payload['floor_id'])
            ->where('is_active', true)
            ->whereNotIn('status', ['inactive', 'merged'])
            ->where('capacity', '>=', $payload['guest_count'])
            ->orderBy('table_no')
            ->get(['id', 'outlet_id', 'floor_id', 'table_no', 'capacity', 'status'])
            ->filter(fn (RestaurantTable $table): bool => ! $this->hasTableConflict(
                $table->id,
                $payload['reservation_date'],
                $payload['checkin_time'],
                $payload['ignore_id'] ?? null
            ))
            ->map(fn (RestaurantTable $table): array => [
                'id' => $table->id,
                'outlet_id' => $table->outlet_id,
                'floor_id' => $table->floor_id,
                'table_no' => $table->table_no,
                'capacity' => $table->capacity,
                'operational_status' => $table->status,
            ])
            ->values();

        return response()->json(['data' => $tables]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'outlet_id' => ['required', 'integer', 'exists:locations,id'],
            'floor_id' => ['nullable', 'integer', 'exists:floors,id'],
            'table_id' => ['required', 'integer', 'exists:tables,id'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:255'],
            'guest_count' => ['required', 'integer', 'min:1'],
            'reservation_date' => ['required', 'date', 'after_or_equal:today'],
            'checkin_time' => ['required', 'date_format:H:i'],
            'special_request' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::in(['pending', 'confirmed', 'arrived', 'seated', 'cancelled', 'completed', 'no_show'])],
        ]);

        $payload['status'] ??= 'pending';
        $this->validateReservationTime($payload['reservation_date'], $payload['checkin_time']);

        $reservation = DB::transaction(function () use ($payload, $request) {
            $table = RestaurantTable::query()->lockForUpdate()->findOrFail($payload['table_id']);
            $payload['floor_id'] ??= $table->floor_id;
            $this->validateTableContext($table, $payload);
            $this->validateAvailability($payload['table_id'], $payload['reservation_date'], $payload['checkin_time']);

            $date = Carbon::parse($payload['reservation_date'])->format('Ymd');
            $latest = Reservation::whereDate('created_at', Carbon::today())->count();
            $reservationNo = 'RES-' . $date . '-' . str_pad($latest + 1, 4, '0', STR_PAD_LEFT);

            $reservation = Reservation::create(array_merge($payload, [
                'reservation_no' => $reservationNo,
                'created_by' => $request->user()->id ?? 1,
            ]));

            $this->syncTableStatus($reservation);

            return $reservation;
        });

        return response()->json(['message' => 'Reservation created successfully', 'data' => $reservation], 201);
    }

    public function show(Reservation $reservation): JsonResponse
    {
        $reservation->load(['outlet', 'floor', 'table']);
        return response()->json(['data' => $reservation]);
    }

    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        $payload = $request->validate([
            'outlet_id' => ['required', 'integer', 'exists:locations,id'],
            'floor_id' => ['nullable', 'integer', 'exists:floors,id'],
            'table_id' => ['required', 'integer', 'exists:tables,id'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:255'],
            'guest_count' => ['required', 'integer', 'min:1'],
            'reservation_date' => ['required', 'date', 'after_or_equal:today'],
            'checkin_time' => ['required', 'date_format:H:i'],
            'special_request' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::in(['pending', 'confirmed', 'arrived', 'seated', 'completed', 'cancelled', 'no_show'])],
        ]);

        $payload['status'] ??= $reservation->status;
        $this->validateReservationTime($payload['reservation_date'], $payload['checkin_time']);

        DB::transaction(function () use ($payload, $request, $reservation) {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $this->assertStatusTransition($reservation->status, $payload['status']);
            $table = RestaurantTable::query()->lockForUpdate()->findOrFail($payload['table_id']);
            $payload['floor_id'] ??= $table->floor_id;
            $this->validateTableContext($table, $payload);
            $this->validateAvailability($payload['table_id'], $payload['reservation_date'], $payload['checkin_time'], $reservation->id);

            // Un-reserve old table if changing tables
            if ($reservation->table_id != $payload['table_id']) {
                $oldTable = RestaurantTable::query()->lockForUpdate()->find($reservation->table_id);
                if ($oldTable && $oldTable->status === 'reserved') {
                    $oldTable->update(['status' => 'available']);
                }
            }

            $reservation->update(array_merge($payload, [
                'updated_by' => $request->user()->id ?? 1,
                'cancelled_at' => in_array($payload['status'], ['cancelled', 'no_show'], true) ? now() : null,
                'cancellation_reason' => in_array($payload['status'], ['cancelled', 'no_show'], true)
                    ? ($request->input('cancellation_reason') ?: $reservation->cancellation_reason)
                    : null,
            ]));

            $this->syncTableStatus($reservation);
        });

        return response()->json(['message' => 'Reservation updated successfully', 'data' => $reservation]);
    }

    public function destroy(Request $request, Reservation $reservation): JsonResponse
    {
        $payload = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($payload, $request, $reservation) {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $this->assertStatusTransition($reservation->status, 'cancelled');
            $reservation->update(['status' => 'cancelled']);
            $reservation->update([
                'cancelled_at' => now(),
                'cancellation_reason' => $payload['cancellation_reason'] ?? null,
                'updated_by' => $request->user()->id ?? 1,
            ]);
            $this->syncTableStatus($reservation);
        });

        return response()->json(['message' => 'Reservation cancelled successfully']);
    }

    private function validateTableContext(RestaurantTable $table, array $payload): void
    {
        $outlet = Location::query()->findOrFail($payload['outlet_id']);
        if (! $outlet->is_active) {
            throw ValidationException::withMessages(['outlet_id' => 'Selected outlet is inactive.']);
        }

        $floor = Floor::query()->findOrFail($payload['floor_id']);
        if (! $floor->is_active) {
            throw ValidationException::withMessages(['floor_id' => 'Selected floor is inactive.']);
        }

        if ((int) $floor->location_id !== (int) $payload['outlet_id']) {
            throw ValidationException::withMessages([
                'floor_id' => 'The floor must belong to the selected outlet.',
            ]);
        }

        if (! $table->is_active || $table->status === 'inactive') {
            throw ValidationException::withMessages(['table_id' => 'Selected table is inactive.']);
        }

        if ((int) $table->outlet_id !== (int) $payload['outlet_id']
            || (int) $table->floor_id !== (int) $payload['floor_id']) {
            throw ValidationException::withMessages([
                'table_id' => 'The table must belong to the selected outlet and floor.',
            ]);
        }

        if ((int) $payload['guest_count'] > (int) $table->capacity) {
            throw ValidationException::withMessages([
                'guest_count' => 'Party size exceeds table capacity.',
            ]);
        }

        if (Carbon::parse($payload['reservation_date'])->isToday()
            && in_array($payload['status'] ?? 'pending', ['pending', 'confirmed'], true)
            && ! in_array($table->status, ['available', 'reserved'], true)) {
            throw ValidationException::withMessages([
                'table_id' => 'Selected table is not available for this time.',
            ]);
        }
    }

    private function validateAvailability(int $tableId, string $date, string $time, ?int $ignoreId = null): void
    {
        if ($this->hasTableConflict($tableId, $date, $time, $ignoreId)) {
            throw ValidationException::withMessages([
                'table_id' => 'This table has just been reserved by another user. Please select another table or time.',
            ]);
        }
    }

    private function hasTableConflict(int $tableId, string $date, string $time, ?int $ignoreId = null): bool
    {
        $requestedStart = Carbon::parse($date.' '.$time);
        $requestedEnd = (clone $requestedStart)->addMinutes(self::DEFAULT_DURATION_MINUTES);

        $query = Reservation::query()
            ->where('table_id', $tableId)
            ->whereDate('reservation_date', $date)
            ->whereNotIn('status', ['cancelled', 'completed', 'no_show']);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        $hasReservationOverlap = $query->get(['id', 'reservation_date', 'checkin_time'])
            ->contains(function (Reservation $reservation) use ($requestedStart, $requestedEnd): bool {
                $existingStart = Carbon::parse(
                    $reservation->reservation_date->format('Y-m-d').' '.$reservation->checkin_time
                );
                $existingEnd = (clone $existingStart)->addMinutes(self::DEFAULT_DURATION_MINUTES);

                return $requestedStart->lt($existingEnd) && $requestedEnd->gt($existingStart);
            });

        if ($hasReservationOverlap) {
            return true;
        }

        $hasActiveOrder = \App\Models\Order::query()
            ->where('table_id', $tableId)
            ->whereNotIn('order_status', ['completed', 'cancelled'])
            ->exists();

        if (! $hasActiveOrder) {
            return false;
        }

        return $requestedStart->lte(now()->addMinutes(self::DEFAULT_DURATION_MINUTES + self::PREPARATION_WINDOW_MINUTES));
    }

    private function validateReservationTime(string $date, string $time): void
    {
        $reservationAt = Carbon::parse($date.' '.$time);
        if ($reservationAt->lt(now()->startOfMinute())) {
            throw ValidationException::withMessages([
                'checkin_time' => 'Reservation time cannot be in the past.',
            ]);
        }
    }

    private function assertStatusTransition(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $allowed = [
            'pending' => ['confirmed', 'cancelled', 'no_show'],
            'confirmed' => ['arrived', 'seated', 'cancelled', 'no_show'],
            'arrived' => ['seated', 'cancelled', 'no_show'],
            'seated' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
            'no_show' => [],
        ];

        if (! in_array($to, $allowed[$from] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => "Reservation cannot move from {$from} to {$to}.",
            ]);
        }
    }

    private function syncTableStatus(Reservation $reservation): void
    {
        $table = RestaurantTable::find($reservation->table_id);
        if (!$table) return;

        $isToday = Carbon::parse($reservation->reservation_date)->isToday();

        if ($reservation->status === 'confirmed' && $isToday) {
            if ($table->status === 'available') {
                $table->update(['status' => 'reserved']);
            }
        } elseif (in_array($reservation->status, ['cancelled', 'completed', 'no_show'])) {
            if ($table->status === 'reserved') {
                $table->update(['status' => 'available']);
            }
        }
    }
}
