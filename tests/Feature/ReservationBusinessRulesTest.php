<?php

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationBusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_overlapping_reservations_and_capacity_are_rejected(): void
    {
        [$location, $floor, $table] = $this->setupTable(capacity: 4);
        $date = now()->addDay()->toDateString();

        $this->postJson('/api/v1/reservations', $this->payload($location, $floor, $table, $date, 4))
            ->assertCreated();

        $this->postJson('/api/v1/reservations', $this->payload($location, $floor, $table, $date, 2))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('table_id');

        $this->postJson('/api/v1/reservations', $this->payload($location, $floor, $table, $date, 5, '14:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('guest_count');
    }

    public function test_invalid_status_transition_is_rejected_and_cancellation_is_soft(): void
    {
        [$location, $floor, $table] = $this->setupTable(capacity: 4);
        $date = now()->toDateString();
        $payload = $this->payload($location, $floor, $table, $date, 2, '18:00');
        $payload['status'] = 'confirmed';

        $reservation = $this->postJson('/api/v1/reservations', $payload)
            ->assertCreated()
            ->json('data');

        $this->putJson('/api/v1/reservations/'.$reservation['id'], array_merge($payload, [
            'status' => 'completed',
        ]))->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->deleteJson('/api/v1/reservations/'.$reservation['id'], [
            'cancellation_reason' => 'Guest cancelled',
        ])->assertOk();

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation['id'],
            'status' => 'cancelled',
            'cancellation_reason' => 'Guest cancelled',
        ]);
        $this->assertDatabaseHas('tables', ['id' => $table->id, 'status' => 'available']);
    }

    /** @return array{0: Location, 1: Floor, 2: RestaurantTable} */
    private function setupTable(int $capacity): array
    {
        $location = Location::query()->create(['name' => 'Reservation Outlet', 'is_active' => true]);
        $floor = Floor::query()->create([
            'name' => 'Ground Floor',
            'code' => 'GF',
            'location_id' => $location->id,
            'is_active' => true,
        ]);
        $table = RestaurantTable::query()->create([
            'outlet_id' => $location->id,
            'floor_id' => $floor->id,
            'table_no' => 'T1',
            'code' => 'T1',
            'capacity' => $capacity,
            'status' => 'available',
            'is_active' => true,
        ]);

        return [$location, $floor, $table];
    }

    private function payload(Location $location, Floor $floor, RestaurantTable $table, string $date, int $guestCount, string $time = '12:00'): array
    {
        return [
            'outlet_id' => $location->id,
            'floor_id' => $floor->id,
            'table_id' => $table->id,
            'customer_name' => 'Walk-in Guest',
            'guest_count' => $guestCount,
            'reservation_date' => $date,
            'checkin_time' => $time,
            'status' => 'pending',
        ];
    }
}
