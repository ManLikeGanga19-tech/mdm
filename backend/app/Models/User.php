<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Mdm\Models\AdminRole;
use App\Mdm\Permission;
use App\Mdm\Role;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'role_id', 'disabled_at', 'invited_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'disabled_at' => 'datetime',
            'last_login_at' => 'datetime',
            'invited_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(AdminRole::class, 'role_id');
    }

    /** Lets code and tests set a role by its built-in key: User::create([..., 'role' => Role::Manager]). */
    public function setRoleAttribute(Role|AdminRole|int|string|null $value): void
    {
        $role = match (true) {
            $value instanceof AdminRole => $value,
            $value instanceof Role => $value->model(),
            is_int($value) || ctype_digit((string) $value) => AdminRole::query()->findOrFail($value), // factories pass ids
            is_string($value) => AdminRole::query()->where('key', $value)->firstOrFail(),
            default => null,
        };
        $this->attributes['role_id'] = $role?->id;
        $this->setRelation('role', $role);
    }

    /** Disabled accounts are signed out on their next click and can't sign in. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->disabled_at === null && $this->role_id !== null;
    }

    public function hasPermission(Permission $permission): bool
    {
        return $this->disabled_at === null && ($this->role?->allows($permission) ?? false);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->isSuperAdmin() ?? false;
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }
}
