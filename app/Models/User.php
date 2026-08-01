<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name', 'email', 'password', 'username', 'phone', 'role_id', 'status', 
    'profile_image', 'employee_id', 'department', 'position', 'joining_date', 'default_outlet_id'
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory, Notifiable, LogsActivity;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'joining_date' => 'date',
        ];
    }



    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function defaultOutlet(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'default_outlet_id');
    }

    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'user_outlets', 'user_id', 'outlet_id');
    }

    public function getAllPermissions(): array
    {
        if ($this->role && $this->role->role_name === 'Super Admin') {
            return ['*'];
        }

        if (!$this->role) {
            return [];
        }

        $rolePermissions = $this->role->permissions()->pluck('permission_name')->toArray();
        return array_values(array_unique($rolePermissions));
    }

    public function isSuperAdmin(): bool
    {
        return strtolower((string) $this->role?->role_name) === 'super admin';
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->getAllPermissions();
        if (in_array('*', $permissions, true)) {
            return true;
        }
        return in_array($permission, $permissions, true);
    }

    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function systemNotifications(): HasMany
    {
        return $this->hasMany(SystemNotification::class);
    }

    public function allowedOutletIds(): array
    {
        if ($this->isSuperAdmin()) {
            $assigned = $this->outlets()->withoutGlobalScopes()->pluck('locations.id')->toArray();

            if (! empty($assigned)) {
                return array_map('intval', $assigned);
            }

            return Location::query()
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $this->outlets()
            ->withoutGlobalScopes()
            ->pluck('locations.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Get the full URL for the user's avatar.
     */
    public function getAvatarUrlAttribute()
    {
        $img = $this->profile_image ?: ($this->attributes['avatar'] ?? null);
        if ($img) {
            if (str_starts_with($img, 'http://') || str_starts_with($img, 'https://')) {
                return $img;
            }
            $cleanPath = ltrim(str_replace('storage/', '', $img), '/');
            return asset('storage/' . $cleanPath);
        }
        return null;
    }
}
