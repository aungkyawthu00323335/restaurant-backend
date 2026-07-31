<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_delivery_with_empty_optional_fields(): void
    {
        $user = User::create([
            'name' => 'Super Admin',
            'username' => 'superadmin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/api/v1/deliveries', [
            'name' => 'Foodpanda',
            'email' => '',
            'phone' => '',
            'note' => '',
            'warehouse_id' => null,
            'is_active' => true,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('deliveries', [
            'name' => 'Foodpanda',
            'email' => null,
            'phone' => null,
            'note' => null,
        ]);
    }

    public function test_can_create_and_update_delivery_with_valid_details(): void
    {
        $user = User::create([
            'name' => 'Super Admin',
            'username' => 'superadmin',
            'email' => 'admin2@example.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $location = Location::create(['name' => 'Main Outlet', 'is_active' => true]);

        $this->actingAs($user);

        $createRes = $this->postJson('/api/v1/deliveries', [
            'name' => 'GrabFood',
            'email' => 'contact@grab.com',
            'phone' => '+959123456789',
            'note' => 'Main partner',
            'warehouse_id' => $location->id,
            'is_active' => true,
        ]);

        $createRes->assertCreated();
        $id = $createRes->json('data.id');

        $updateRes = $this->putJson("/api/v1/deliveries/{$id}", [
            'name' => 'GrabFood Express',
            'email' => '',
            'phone' => '+959987654321',
            'note' => 'Updated partner',
            'warehouse_id' => null,
            'is_active' => true,
        ]);

        $updateRes->assertOk();
        $this->assertDatabaseHas('deliveries', [
            'id' => $id,
            'name' => 'GrabFood Express',
            'email' => null,
            'phone' => '+959987654321',
        ]);
    }

    public function test_index_returns_global_and_warehouse_specific_deliveries(): void
    {
        $user = User::create([
            'name' => 'Super Admin',
            'username' => 'superadmin',
            'email' => 'admin3@example.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $location1 = Location::create(['name' => 'Main Outlet', 'is_active' => true]);
        $location2 = Location::create(['name' => 'Branch Outlet', 'is_active' => true]);

        Delivery::create(['name' => 'Global Express', 'warehouse_id' => null, 'is_active' => true]);
        Delivery::create(['name' => 'Outlet 1 Local', 'warehouse_id' => $location1->id, 'is_active' => true]);
        Delivery::create(['name' => 'Outlet 2 Local', 'warehouse_id' => $location2->id, 'is_active' => true]);

        $this->actingAs($user);

        // Fetching for location 1 should return Global Express AND Outlet 1 Local
        $res1 = $this->getJson("/api/v1/deliveries?warehouse_id={$location1->id}");
        $res1->assertOk();
        $names1 = collect($res1->json('data.data'))->pluck('name')->all();
        $this->assertContains('Global Express', $names1);
        $this->assertContains('Outlet 1 Local', $names1);
        $this->assertNotContains('Outlet 2 Local', $names1);
    }
}
