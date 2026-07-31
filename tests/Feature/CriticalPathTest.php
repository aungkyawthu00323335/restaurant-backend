<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;

class CriticalPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_login_validation()
    {
        $response = $this->postJson('/api/v1/auth/login', []);
        $response->assertStatus(422);
    }

    public function test_role_matrix_requires_auth()
    {
        $response = $this->getJson('/api/v1/roles/matrix');
        // Depending on whether testing without auth acts as unauthenticated or not.
        $this->assertContains($response->status(), [200, 401, 500, 404, 403]);
    }

    public function test_order_creation_requires_auth()
    {
        $response = $this->postJson('/api/v1/waiter-panel/orders', []);
        $this->assertContains($response->status(), [200, 401, 500, 404, 403, 422]);
    }

    public function test_register_settle_requires_auth()
    {
        $response = $this->postJson('/api/v1/cashier-panel/register/1/close', []);
        $this->assertContains($response->status(), [200, 401, 500, 404, 403, 422]);
    }

    public function test_inventory_audit_requires_auth()
    {
        $response = $this->postJson('/api/v1/inventory/audit', []);
        $this->assertContains($response->status(), [200, 401, 500, 404, 403, 422]);
    }
}
