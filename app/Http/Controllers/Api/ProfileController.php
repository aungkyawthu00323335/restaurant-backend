<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    /**
     * Update the authenticated user's profile.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:20',
            'password' => ['nullable', 'string', 'min:6'],
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }
        if (isset($validated['phone'])) {
            $user->phone = $validated['phone'];
        }
        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        if ($request->hasFile('avatar')) {
            $oldImg = $user->profile_image;
            if ($oldImg) {
                Storage::disk('public')->delete(str_replace('storage/', '', $oldImg));
            }
            
            $file = $request->file('avatar');
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->storeAs('avatars', $filename, 'public');
            $user->profile_image = 'avatars/' . $filename;
        }

        $user->save();

        $userData = $user->only(['id', 'name', 'email', 'phone', 'username']);
        $userData['avatar_url'] = $user->avatar_url;
        $userData['profile_image'] = $user->avatar_url;

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $userData,
        ]);
    }

    /**
     * Get the authenticated user's activity log.
     */
    public function activityLog(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = $request->query('per_page', 15);

        $logs = $user->activityLogs()
            ->latest()
            ->paginate($perPage);

        return response()->json($logs);
    }
}
