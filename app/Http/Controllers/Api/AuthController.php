<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\LoginHistory;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    /**
     * User authentication login.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'login' => 'nullable|string', // username or email
            'email' => 'nullable|string',
            'password' => 'required|string',
        ]);

        $login = $request->input('login') ?: $request->input('email');
        if (! is_string($login) || trim($login) === '') {
            return new JsonResponse([
                'message' => 'The username or email field is required.',
                'errors' => [
                    'login' => ['The username or email field is required.'],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $login = trim($login);
        $password = $request->input('password');

        // Retrieve user by username or email
        $user = User::with(['outlets', 'defaultOutlet', 'role'])
            ->where('username', $login)
            ->orWhere('email', $login)
            ->first();

        if (!$user) {
            return new JsonResponse([
                'message' => 'Invalid username or password',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Verify status
        if ($user->status !== 'active') {
            return new JsonResponse([
                'message' => 'Your user account is ' . $user->status . '. Please contact administration.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Verify password
        if (!Hash::check($password, $user->password)) {
            return new JsonResponse([
                'message' => 'Invalid username or password',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Every non-Super Admin must have one assigned outlet. Never grant all
        // outlets implicitly when the assignment is missing.
        if ($user->outlets->isEmpty()) {
            if (! $user->isSuperAdmin()) {
                return new JsonResponse(['message' => 'No outlet is assigned to this user. Please contact an administrator.'], Response::HTTP_FORBIDDEN);
            }
        }

        // Create login history session
        $token = Str::random(60);
        LoginHistory::create([
            'user_id' => $user->id,
            'login_time' => now(),
            'ip_address' => $request->ip(),
            'device' => $request->header('User-Agent', 'Unknown Device'),
            'status' => 'active',
            'session_token' => $token,
        ]);

        // Audit Log
        ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'Logged in successfully',
            'module' => 'Auth',
        ]);

        return new JsonResponse([
            'message' => 'Login successful',
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * User logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->header('X-USER-TOKEN');

        if ($token) {
            $session = LoginHistory::where('session_token', $token)->first();
            if ($session) {
                $session->update([
                    'status' => 'logged_out',
                    'logout_time' => now(),
                ]);

                ActivityLog::create([
                    'user_id' => $session->user_id,
                    'action' => 'Logged out successfully',
                    'module' => 'Auth',
                ]);
            }
        }

        return new JsonResponse([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Fetch authenticated user details.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return new JsonResponse([
                'message' => 'Unauthenticated user.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $user->load(['outlets', 'defaultOutlet', 'role']);

        return new JsonResponse($this->userPayload($user));
    }

    private function userPayload(User $user): array
    {
        $outlets = $user->isSuperAdmin()
            ? Location::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
            : $user->outlets;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'profile_image' => $user->profile_image,
            'avatar_url' => $user->avatar_url,
            'employee_id' => $user->employee_id,
            'department' => $user->department,
            'position' => $user->position,
            'role_id' => $user->role_id,
            'role_name' => $user->role ? $user->role->role_name : 'No Role',
            'permissions' => $user->getAllPermissions(),
            'default_outlet_id' => $user->default_outlet_id,
            'outlets' => $outlets->map(fn($o) => [
                'id' => $o->id,
                'name' => $o->name,
            ])->values(),
        ];
    }
}
