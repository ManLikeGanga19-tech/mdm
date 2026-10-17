<?php

namespace App\Mdm;

/**
 * What an admin-site account may do. Defined here in code; super admins assign them to roles on the Roles &
 * permissions page (mdm_role_permissions). The super admin role always has all of them.
 */
enum Permission: string
{
    case FleetView = 'fleet.view';
    case PhonesControl = 'phones.control';
    case PhonesErase = 'phones.erase';
    case PhonesRules = 'phones.rules';
    case LiveView = 'phones.live';
    case HandoverRecord = 'handover.record';
    case HandoverRelease = 'handover.release';
    case ClerksManage = 'clerks.manage';
    case RosterManage = 'roster.manage';
    case SetupManage = 'setup.manage';
    case EnrollmentManage = 'enrollment.manage';
    case AlertsManage = 'alerts.manage';
    case ReportsView = 'reports.view';
    case WebsitesView = 'websites.view';
    case AuditView = 'audit.view';
    case AccountsManage = 'accounts.manage';
    case RolesManage = 'roles.manage';

    public function label(): string
    {
        return match ($this) {
            self::FleetView => 'View phones and the dashboard',
            self::PhonesControl => 'Lock, restart, reset screen lock; edit phone details',
            self::PhonesErase => 'Erase phones (factory reset)',
            self::PhonesRules => 'Change a phone\'s rules (hotspot, blocked apps)',
            self::LiveView => 'Start live view',
            self::HandoverRecord => 'Record shift handovers',
            self::HandoverRelease => 'Release a handover lock',
            self::ClerksManage => 'Add and edit clerks (incl. CSV import)',
            self::RosterManage => 'Manage crews and the shift roster',
            self::SetupManage => 'Change groups & rules, work sites & apps, shifts & peak hours',
            self::EnrollmentManage => 'Create and disable enrollment codes',
            self::AlertsManage => 'Change alert rules (what is emailed, to whom)',
            self::ReportsView => 'View the shift report',
            self::WebsitesView => 'View websites visited (website log, top sites)',
            self::AuditView => 'View the audit log',
            self::AccountsManage => 'Manage admin-site accounts',
            self::RolesManage => 'Manage roles & permissions',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::FleetView, self::PhonesControl, self::PhonesErase, self::PhonesRules, self::LiveView => 'Phones',
            self::HandoverRecord, self::HandoverRelease, self::ClerksManage, self::RosterManage => 'Shifts',
            self::SetupManage, self::EnrollmentManage, self::AlertsManage => 'Setup',
            self::ReportsView, self::WebsitesView => 'Reports',
            self::AuditView, self::AccountsManage, self::RolesManage => 'Administration',
        };
    }

    /** Shown in red on the grid: give these out sparingly. */
    public function isSensitive(): bool
    {
        return in_array($this, [self::PhonesErase, self::WebsitesView, self::AccountsManage, self::RolesManage], true);
    }

    /**
     * Permissions of the built-in roles when they are first created: exactly what each role could do before roles
     * became editable.
     *
     * @return list<self>
     */
    public static function defaultsFor(Role $role): array
    {
        return match ($role) {
            Role::SuperAdmin => self::cases(),
            Role::Manager => [self::FleetView, self::ReportsView, self::WebsitesView, self::AuditView],
            Role::Coordinator => [self::HandoverRecord],
        };
    }
}
