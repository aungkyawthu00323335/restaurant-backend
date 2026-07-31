<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserOutletAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_outlet_admin_can_create_users_only_for_their_assigned_outlet(): void
    {
        $main = Location::create(['name' => 'Outlet 1', 'is_active' => true]);
        $branch = Location::create(['name' => 'Outlet 2', 'is_active' => true]);
        $superRole = $this->role('Super Admin');
        $outletAdminRole = $this->role('Outlet Admin');

        $actor = User::create([
            'name' => 'Outlet 1 Admin',
            'username' => 'outlet1admin',
            'email' => 'outlet1admin@example.com',
            'password' => Hash::make('password'),
            'role_id' => $outletAdminRole->id,
            'status' => 'active',
            'default_outlet_id' => $main->id,
        ]);
        $actor->outlets()->attach($main->id);

        $listData = $this->getJson('/api/v1/users-list-data')->assertOk()->json();

        $this->assertSame([$main->id], collect($listData['outlets'])->pluck('id')->all());
        $this->assertFalse(
            collect($listData['roles'])->contains(fn ($role): bool => strtolower((string) $role['role_name']) === 'super admin')
        );

        $this->postJson('/api/v1/users', [
            'name' => 'Outlet 1 Waiter',
            'username' => 'outlet1waiter',
            'email' => 'outlet1waiter@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_id' => $outletAdminRole->id,
            'status' => 'active',
            'default_outlet_id' => $main->id,
            'outlets' => [$main->id],
        ])->assertCreated();

        $createdUser = User::where('email', 'outlet1waiter@example.com')->firstOrFail();
        $this->assertSame([$main->id], $createdUser->outlets()->pluck('locations.id')->all());

        $this->postJson('/api/v1/users', [
            'name' => 'Outlet 2 Waiter',
            'username' => 'outlet2waiter',
            'email' => 'outlet2waiter@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_id' => $outletAdminRole->id,
            'status' => 'active',
            'default_outlet_id' => $branch->id,
            'outlets' => [$branch->id],
        ])->assertForbidden();

        $this->postJson('/api/v1/users', [
            'name' => 'Fake Super',
            'username' => 'fakesuper',
            'email' => 'fakesuper@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_id' => $superRole->id,
            'status' => 'active',
        ])->assertForbidden();
    }

    public function test_super_admin_login_returns_all_active_outlets_without_assignments(): void
    {
        $main = Location::create(['name' => 'Outlet 1', 'is_active' => true]);
        $branch = Location::create(['name' => 'Outlet 2', 'is_active' => true]);
        Location::create(['name' => 'Closed Outlet', 'is_active' => false]);
        $superRole = $this->role('Super Admin');

        User::create([
            'name' => 'Super Admin',
            'username' => 'superadmin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('secret123'),
            'role_id' => $superRole->id,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'superadmin',
            'password' => 'secret123',
        ])->assertOk()->json();

        $outletIds = collect($response['user']['outlets'])->pluck('id')->sort()->values()->all();
        $this->assertSame([$main->id, $branch->id], $outletIds);
        $this->assertSame(['*'], $response['user']['permissions']);
    }

    private function role(string $name): Role
    {
        return Role::query()->firstOrCreate(
            ['role_name' => $name],
            ['description' => $name, 'status' => 'active']
        );
    }
}
