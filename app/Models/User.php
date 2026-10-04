<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'organization_id',
        'phone',
        'avatar',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role');
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class, 'user_id');
    }

    public function hasRole(string|array $roles): bool
    {
        $roleSlugs = $this->roles->pluck('slug')->toArray();

        if (is_array($roles)) {
            return ! empty(array_intersect($roles, $roleSlugs));
        }

        return in_array($roles, $roleSlugs, true);
    }

    public function hasPermission(string|array $permissions): bool
    {
        if ($this->hasRole('super-admin')) {
            return true;
        }

        $userPerms = $this->getAllPermissions();
        $checkList = is_array($permissions) ? $permissions : [$permissions];

        foreach ($checkList as $required) {
            if ($userPerms->contains($required)) {
                return true;
            }

            // Wildcard matching: e.g. user permission 'attendance.*' grants 'attendance.view'
            foreach ($userPerms as $userPerm) {
                if (str_ends_with($userPerm, '.*')) {
                    $prefix = substr($userPerm, 0, -2);
                    if (str_starts_with($required, $prefix.'.')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function getAllPermissions(): Collection
    {
        if ($this->hasRole('super-admin')) {
            return Permission::pluck('slug');
        }

        return $this->roles->flatMap(function ($role) {
            return $role->permissions->pluck('slug');
        })->unique()->values();
    }

    public function assignRole(string|Role $role): void
    {
        $roleModel = is_string($role)
            ? Role::where('slug', $role)->firstOrFail()
            : $role;

        $this->roles()->syncWithoutDetaching([$roleModel->id]);
    }

    public function removeRole(string|Role $role): void
    {
        $roleModel = is_string($role)
            ? Role::where('slug', $role)->first()
            : $role;

        if ($roleModel) {
            $this->roles()->detach($roleModel->id);
        }
    }
}
