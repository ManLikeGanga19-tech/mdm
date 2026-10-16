<?php

namespace App\Mdm;

/**
 * The built-in admin-site roles (mdm_roles.key). Their permissions are editable on the Roles & permissions page,
 * except the super admin's, which always has everything. Further roles can be added there (App\Mdm\Models\AdminRole).
 */
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Manager = 'manager';
    case Coordinator = 'coordinator';

    public function name(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super admin',
            self::Manager => 'Manager',
            self::Coordinator => 'Shift coordinator',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Everything, including accounts and roles. Cannot be changed.',
            self::Manager => 'Looks at phones, reports and the audit log; changes nothing.',
            self::Coordinator => 'Records the shift handovers.',
        };
    }

    /** The role record for this built-in role. */
    public function model(): Models\AdminRole
    {
        return Models\AdminRole::query()->where('key', $this->value)->firstOrFail();
    }
}
