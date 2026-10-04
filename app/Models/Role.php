<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_role');
    }

    public function givePermission(string|Permission|array $permission): void
    {
        if (is_array($permission)) {
            foreach ($permission as $p) {
                $this->givePermission($p);
            }
            return;
        }

        $perm = is_string($permission)
            ? Permission::firstOrCreate(['slug' => $permission], ['name' => $permission, 'group' => 'general'])
            : $permission;

        $this->permissions()->syncWithoutDetaching([$perm->id]);
    }

    public function revokePermission(string|Permission $permission): void
    {
        $perm = is_string($permission)
            ? Permission::where('slug', $permission)->first()
            : $permission;

        if ($perm) {
            $this->permissions()->detach($perm->id);
        }
    }

    public function syncPermissions(array $permissions): void
    {
        $permissionIds = [];
        foreach ($permissions as $perm) {
            if ($perm instanceof Permission) {
                $permissionIds[] = $perm->id;
            } elseif (is_numeric($perm)) {
                $p = Permission::find((int) $perm);
                if ($p) {
                    $permissionIds[] = $p->id;
                }
            } elseif (is_string($perm)) {
                $p = Permission::where('slug', $perm)->first();
                if ($p) {
                    $permissionIds[] = $p->id;
                }
            }
        }
        $this->permissions()->sync($permissionIds);
    }
}
