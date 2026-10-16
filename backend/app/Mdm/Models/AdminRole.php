<?php

namespace App\Mdm\Models;

use App\Mdm\Permission;
use App\Mdm\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * An admin-site role: a name and a set of permissions (App\Mdm\Permission, stored in mdm_role_permissions). Built-in
 * roles have a key (App\Mdm\Role); the super admin role always has every permission and can't be changed.
 */
#[Fillable(['key', 'name', 'description'])]
class AdminRole extends Model
{
    protected $table = 'mdm_roles';

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_id');
    }

    public function builtIn(): ?Role
    {
        return $this->key ? Role::tryFrom($this->key) : null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->key === Role::SuperAdmin->value;
    }

    /** @return list<string> permission values */
    public function permissionValues(): array
    {
        if ($this->isSuperAdmin()) {
            return array_column(Permission::cases(), 'value');
        }

        return $this->permissionCache ??= DB::table('mdm_role_permissions')
            ->where('role_id', $this->id)->orderBy('permission')->pluck('permission')->all();
    }

    public function allows(Permission $permission): bool
    {
        return in_array($permission->value, $this->permissionValues(), true);
    }

    /**
     * Replaces the role's permissions (unknown values are dropped). Returns what was added and removed, for the audit
     * log. The super admin role is never changed.
     *
     * @param  list<string>  $values
     * @return array{added: list<string>, removed: list<string>}
     */
    public function syncPermissions(array $values): array
    {
        if ($this->isSuperAdmin()) {
            return ['added' => [], 'removed' => []];
        }
        $valid = array_column(Permission::cases(), 'value');
        $new = array_values(array_intersect($valid, $values));
        $old = $this->permissionValues();

        DB::transaction(function () use ($new) {
            DB::table('mdm_role_permissions')->where('role_id', $this->id)->delete();
            DB::table('mdm_role_permissions')->insert(array_map(fn ($p) => ['role_id' => $this->id, 'permission' => $p], $new));
        });
        $this->permissionCache = null;

        return ['added' => array_values(array_diff($new, $old)), 'removed' => array_values(array_diff($old, $new))];
    }
}
