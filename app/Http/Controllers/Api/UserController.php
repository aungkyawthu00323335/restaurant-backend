<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Services\ApiImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->input('search');
        $status = $request->input('status');
        $sortCol = $request->input('sort_col', 'id');
        $sortDir = $request->input('sort_dir', 'desc');
        $perPage = (int) $request->input('per_page', 10);

        $query = User::with(['outlets', 'defaultOutlet', 'role']);
        $this->scopeUsersForActor($query, $request->user());

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('employee_id', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('status', $status);
        }
        if ($request->filled('role_id')) {
            $query->where('role_id', (int) $request->input('role_id'));
        }

        // Validate sort column
        $allowedCols = ['id', 'name', 'username', 'email', 'employee_id', 'status', 'created_at'];
        if (!in_array($sortCol, $allowedCols)) {
            $sortCol = 'id';
        }
        $query->orderBy($sortCol, $sortDir === 'asc' ? 'asc' : 'desc');

        $users = $query->paginate($perPage);
        $users->getCollection()->each(function (User $user) {
            $user->setAttribute('avatar_url', $user->avatar_url);
        });

        return new JsonResponse($users);
    }

    /**
     * Fetch list of outlets and roles for form binding.
     */
    public function listData(Request $request): JsonResponse
    {
        $actor = $request->user();
        $outlets = Location::query()
            ->where('is_active', true)
            ->when(
                $actor instanceof User && ! $actor->isSuperAdmin(),
                fn ($q) => $q->whereIn('id', $actor->allowedOutletIds())
            )
            ->orderBy('name')
            ->get();
        $roles = Role::query()
            ->where('status', 'active')
            ->when(
                $actor instanceof User && ! $actor->isSuperAdmin(),
                fn ($q) => $q->whereRaw('LOWER(role_name) <> ?', ['super admin'])
            )
            ->orderBy('role_name')
            ->get();
        
        return new JsonResponse([
            'outlets' => $outlets,
            'roles' => $roles,
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request, ApiImageStorage $images): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'nullable|string|max:255|unique:users,username',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:45',
            'password' => 'required|string|min:6|confirmed',
            'role_id' => 'nullable|exists:roles,id',
            'status' => 'nullable|string|in:active,inactive,suspended',
            'employee_id' => 'nullable|string|max:120|unique:users,employee_id',
            'department' => 'nullable|string|max:120',
            'position' => 'nullable|string|max:120',
            'joining_date' => 'nullable|date',
            'default_outlet_id' => 'nullable|exists:locations,id',
            'outlets' => 'nullable|array',
            'outlets.*' => 'exists:locations,id',
            'profile_image_base64' => 'nullable|string',
            'profile_image' => 'nullable|string',
        ]);
        $this->validateOutletAssignment($request);

        DB::beginTransaction();
        try {
            $profileImage = $images->storeBase64(
                $request->input('profile_image_base64', $request->input('profile_image')),
                'users'
            );

            // Create user
            $user = User::create([
                'name' => $request->input('name'),
                'username' => $request->input('username'),
                'email' => $request->input('email'),
                'phone' => $request->input('phone'),
                'password' => Hash::make($request->input('password')),
                'role_id' => $request->input('role_id'),

                'status' => $request->input('status', 'active'),
                'profile_image' => $profileImage,
                'employee_id' => $this->nullableString($request->input('employee_id')),
                'department' => $this->nullableString($request->input('department')),
                'position' => $this->nullableString($request->input('position')),
                'joining_date' => $request->input('joining_date'),
                'default_outlet_id' => $request->input('default_outlet_id'),
            ]);

            $user->outlets()->sync($this->normalizedOutletIds($request, null));


            // Log activity
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => "Created user account: {$user->username}",
                'module' => 'User Management',
            ]);

            DB::commit();

            return new JsonResponse([
                'message' => 'User created successfully',
                'user_id' => $user->id,
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            DB::rollBack();
            return new JsonResponse([
                'message' => 'Failed to create user: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Show detailed user configuration.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = User::with(['outlets', 'defaultOutlet', 'role'])->find($id);

        if (!$user) {
            return new JsonResponse([
                'message' => 'User not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $this->ensureActorCanManageUser($request, $user);

        $user->setAttribute('avatar_url', $user->avatar_url);

        return new JsonResponse($user);
    }

    /**
     * Update user details.
     */
    public function update(Request $request, int $id, ApiImageStorage $images): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return new JsonResponse([
                'message' => 'User not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['nullable', 'string', 'max:255', Rule::unique('users')->ignore($user->id)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => 'nullable|string|max:45',
            'password' => 'nullable|string|min:6|confirmed',
            'role_id' => 'nullable|exists:roles,id',
            'status' => 'nullable|string|in:active,inactive,suspended',
            'employee_id' => ['nullable', 'string', 'max:120', Rule::unique('users')->ignore($user->id)],
            'department' => 'nullable|string|max:120',
            'position' => 'nullable|string|max:120',
            'joining_date' => 'nullable|date',
            'default_outlet_id' => 'nullable|exists:locations,id',
            'outlets' => 'nullable|array',
            'outlets.*' => 'exists:locations,id',
            'profile_image_base64' => 'nullable|string',
            'profile_image' => 'nullable|string',
        ]);
        $this->ensureActorCanManageUser($request, $user->loadMissing(['outlets', 'role']));
        $this->validateOutletAssignment($request, $user);

        DB::beginTransaction();
        try {
            $user->name = $request->input('name');
            $user->username = $request->input('username');
            $user->email = $request->input('email');
            $user->phone = $request->input('phone');
            $user->role_id = $request->input('role_id');

            $user->status = $request->input('status', $user->status ?: 'active');
            
            $oldImage = $user->profile_image;
            $imageInput = $request->input('profile_image_base64', $request->input('profile_image'));
            if ($imageInput !== null && trim((string) $imageInput) !== '') {
                $newImage = $images->storeBase64($imageInput, 'users');
                if ($newImage) {
                    $user->profile_image = $newImage;
                }
            }
            
            $user->employee_id = $this->nullableString($request->input('employee_id'));
            $user->department = $this->nullableString($request->input('department'));
            $user->position = $this->nullableString($request->input('position'));
            $user->joining_date = $request->input('joining_date');
            $user->default_outlet_id = $request->input('default_outlet_id');

            if ($request->filled('password')) {
                $user->password = Hash::make($request->input('password'));
            }

            $user->save();

            $user->outlets()->sync($this->normalizedOutletIds($request, $user));


            // Log activity
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => "Updated user account: {$user->username}",
                'module' => 'User Management',
            ]);

            DB::commit();

            if (! empty($newImage ?? null) && ! empty($oldImage ?? null) && $oldImage !== $newImage) {
                $images->delete($oldImage, 'users');
            }

            $updatedUser = $user->fresh()->load(['outlets', 'defaultOutlet', 'role']);
            $updatedUser->setAttribute('avatar_url', $updatedUser->avatar_url);

            return new JsonResponse([
                'message' => 'User updated successfully',
                'user' => $updatedUser,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return new JsonResponse([
                'message' => 'Failed to update user: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete user account.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return new JsonResponse([
                'message' => 'User not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        // Rule: A user cannot delete their own account
        if ($user->id === $request->user()->id) {
            return new JsonResponse([
                'message' => 'Validation Error: You cannot delete your own account.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->ensureActorCanManageUser($request, $user->loadMissing(['outlets', 'role']));

        DB::beginTransaction();
        try {
            $username = $user->username;
            $user->delete();

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => "Deleted user account: {$username}",
                'module' => 'User Management',
            ]);

            DB::commit();

            return new JsonResponse([
                'message' => 'User deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return new JsonResponse([
                'message' => 'Failed to delete user: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function validateOutletAssignment(Request $request, ?User $user = null): void
    {
        $actor = $request->user();
        $roleId = $request->input('role_id', $user?->role_id);
        $roleName = $roleId !== null
            ? (string) Role::query()->whereKey($roleId)->value('role_name')
            : '';
        $isSuperAdmin = strtolower($roleName) === 'super admin';

        if ($roleId === null && $user?->isSuperAdmin()) {
            $isSuperAdmin = true;
        }

        if ($actor instanceof User && ! $actor->isSuperAdmin()) {
            if ($isSuperAdmin || $user?->isSuperAdmin()) {
                abort(403, 'Only Super Admin can create or edit Super Admin users.');
            }
        }

        $outlets = $this->normalizedOutletIds($request, $user);
        $defaultOutlet = $request->has('default_outlet_id')
            ? $request->input('default_outlet_id')
            : $user?->default_outlet_id;

        if ($isSuperAdmin) {
            return;
        }

        if (count($outlets) !== 1) {
            abort(422, 'Each non-Super Admin user must be assigned to exactly one outlet.');
        }

        if ($defaultOutlet === null || (int) $defaultOutlet !== $outlets[0]) {
            abort(422, 'The default outlet must match the assigned outlet.');
        }

        if ($actor instanceof User && ! $actor->isSuperAdmin()) {
            $allowed = $actor->allowedOutletIds();
            if (! in_array($outlets[0], $allowed, true)) {
                abort(403, 'You can assign users only to your own outlet.');
            }
        }
    }

    private function scopeUsersForActor($query, ?User $actor): void
    {
        if (! $actor instanceof User || $actor->isSuperAdmin()) {
            return;
        }

        $allowedOutletIds = $actor->allowedOutletIds();

        $query->whereHas('outlets', function ($q) use ($allowedOutletIds): void {
            $q->whereIn('locations.id', $allowedOutletIds);
        })->whereDoesntHave('role', function ($q): void {
            $q->whereRaw('LOWER(role_name) = ?', ['super admin']);
        });
    }

    private function ensureActorCanManageUser(Request $request, User $target): void
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->isSuperAdmin()) {
            return;
        }

        if ($target->isSuperAdmin()) {
            abort(403, 'Only Super Admin can manage Super Admin users.');
        }

        $allowedOutletIds = $actor->allowedOutletIds();
        $targetOutletIds = $target->outlets()
            ->withoutGlobalScopes()
            ->pluck('locations.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($targetOutletIds === [] || array_diff($targetOutletIds, $allowedOutletIds) !== []) {
            abort(403, 'You can manage users only in your assigned outlet.');
        }
    }

    private function normalizedOutletIds(Request $request, ?User $user): array
    {
        $source = $request->has('outlets')
            ? (array) $request->input('outlets', [])
            : ($user
                ? $user->outlets()->withoutGlobalScopes()->pluck('locations.id')->all()
                : []);

        return array_values(array_unique(array_map('intval', $source)));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
