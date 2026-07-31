<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    /**
     * Display a listing of all roles.
     */
    public function index(Request $request)
    {
        $roles = Role::withCount(['permissions', 'users'])
            ->when(
                ! $request->user()?->isSuperAdmin(),
                fn ($q) => $q->whereRaw('LOWER(role_name) <> ?', ['super admin'])
            )
            ->orderBy('role_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }

    /**
     * Get permission matrix grouped by module name.
     */
    public function matrix()
    {
        $permissions = Permission::all();

        $grouped = $permissions->groupBy('module_name')->map(function ($items, $module) {
            return [
                'module_name' => $module,
                'permissions' => $items->map(function ($p) {
                    return [
                        'id' => $p->id,
                        'permission_name' => $p->permission_name,
                        'label' => $p->label,
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $grouped,
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'role_name' => 'required|string|max:100|unique:roles,role_name',
            'description' => 'nullable|string|max:500',
            'status' => 'nullable|string|in:active,inactive',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        $this->guardSuperAdminRole($request, $validated['role_name']);

        DB::beginTransaction();
        try {
            $role = Role::create([
                'role_name' => $validated['role_name'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? 'active',
            ]);

            if (!empty($validated['permission_ids'])) {
                $role->permissions()->sync($validated['permission_ids']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully.',
                'data' => $role->load('permissions'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create role: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified role.
     */
    public function show($id)
    {
        $role = Role::with('permissions')->find($id);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found.',
            ], 404);
        }

        if (! request()->user()?->isSuperAdmin() && strtolower((string) $role->role_name) === 'super admin') {
            return response()->json([
                'success' => false,
                'message' => 'Only Super Admin can view this role.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $role,
        ]);
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found.',
            ], 404);
        }

        $validated = $request->validate([
            'role_name' => 'required|string|max:100|unique:roles,role_name,' . $id,
            'description' => 'nullable|string|max:500',
            'status' => 'nullable|string|in:active,inactive',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        $this->guardSuperAdminRole($request, $role->role_name);
        $this->guardSuperAdminRole($request, $validated['role_name']);

        DB::beginTransaction();
        try {
            $role->update([
                'role_name' => $validated['role_name'],
                'description' => $validated['description'] ?? $role->description,
                'status' => $validated['status'] ?? $role->status,
            ]);

            if (isset($validated['permission_ids'])) {
                $role->permissions()->sync($validated['permission_ids']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully.',
                'data' => $role->load('permissions'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update role: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Request $request, $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found.',
            ], 404);
        }

        if ($role->role_name === 'Super Admin') {
            return response()->json([
                'success' => false,
                'message' => 'Super Admin role cannot be deleted.',
            ], 403);
        }

        if (! $request->user()?->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only Super Admin can delete roles.',
            ], 403);
        }

        if ($role->users()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Role is assigned to users and cannot be deleted.',
            ], 400);
        }

        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully.',
        ]);
    }

    private function guardSuperAdminRole(Request $request, string $roleName): void
    {
        if ($request->user()?->isSuperAdmin()) {
            return;
        }

        if (strtolower(trim($roleName)) === 'super admin') {
            abort(403, 'Only Super Admin can create or edit the Super Admin role.');
        }
    }
}
