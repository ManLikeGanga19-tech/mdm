<?php

namespace App\Mdm;

/** Admin-site roles. Super admins control phones; managers only look; coordinators only record shift handovers. */
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Manager = 'manager';
    case Coordinator = 'coordinator';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super admin',
            self::Manager => 'Manager (read-only)',
            self::Coordinator => 'Shift coordinator',
        };
    }
}
