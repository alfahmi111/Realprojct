<?php

namespace App\Authorization;

/**
 * Parties and roles (spec §3, REQ-0057…REQ-0065). Each role sees only its own interface inside the one
 * PWA (REQ-0271, REQ-0494); the property scope is applied separately (UserScope).
 */
enum Role: string
{
    case PlatformAdmin = 'platform_admin';
    case Owner = 'owner';
    case InstitutionalOwner = 'institutional_owner';
    case Manager = 'manager';
    case Accountant = 'accountant';
    case LeasingOfficer = 'leasing_officer';
    case MaintenanceSupervisor = 'maintenance_supervisor';
    case Technician = 'technician';
    case Tenant = 'tenant';

    public function labelAr(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'مدير المنصة',
            self::Owner => 'المالك',
            self::InstitutionalOwner => 'المالك المؤسسي',
            self::Manager => 'مدير الأصل',
            self::Accountant => 'محاسب',
            self::LeasingOfficer => 'مسؤول تأجير',
            self::MaintenanceSupervisor => 'مشرف صيانة',
            self::Technician => 'فني',
            self::Tenant => 'المستأجر',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'Platform Admin',
            self::Owner => 'Owner',
            self::InstitutionalOwner => 'Institutional Owner',
            self::Manager => 'Asset/Property Manager',
            self::Accountant => 'Accountant',
            self::LeasingOfficer => 'Leasing Officer',
            self::MaintenanceSupervisor => 'Maintenance Supervisor',
            self::Technician => 'Technician',
            self::Tenant => 'Tenant',
        };
    }

    /** Interface (panel) the role lands on inside the single app. */
    public function portal(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'platform',
            self::Owner => 'owner',
            self::InstitutionalOwner, self::Manager, self::Accountant, self::LeasingOfficer, self::MaintenanceSupervisor => 'manager',
            self::Technician => 'technician',
            self::Tenant => 'tenant',
        };
    }

    /** Permissions by role, from the "what they see and do" column of spec §3. */
    public function permissions(): array
    {
        return match ($this) {
            self::PlatformAdmin => ['platform.subscribers', 'platform.plans', 'platform.qr_boards', 'platform.settings'],
            // REQ-0058: dashboard, statement, approve maintenance above the cap, see the manager's fee.
            self::Owner => ['owner.dashboard', 'owner.statement', 'owner.approve_above_cap', 'owner.manager_fee', 'owner.budget'],
            // REQ-0059: self-managed ("ذاتي") agreement — full operations for its own team.
            self::InstitutionalOwner => array_merge(self::Manager->permissions(), ['owner.dashboard', 'owner.statement', 'owner.budget', 'owner.approve_above_cap']),
            // REQ-0060: everything operational within the management agreement.
            self::Manager => ['properties.*', 'leasing.*', 'finance.*', 'maintenance.*', 'assets.*', 'prospects.*', 'reports.*', 'settings.subscriber', 'users.manage', 'approvals.decide', 'documents.parties'],
            // REQ-0061: staff permissions by role.
            self::Accountant => ['finance.*', 'reports.finance', 'properties.view', 'leasing.view'],
            self::LeasingOfficer => ['leasing.*', 'prospects.*', 'properties.view'],
            self::MaintenanceSupervisor => ['maintenance.*', 'assets.*', 'properties.view'],
            // REQ-0062: only work orders assigned to them.
            self::Technician => ['maintenance.assigned', 'assets.view_assigned', 'inventory.perform'],
            // REQ-0063: own unit, lease, invoices, registered assets, maintenance requests.
            self::Tenant => ['tenant.unit', 'tenant.lease', 'tenant.invoices', 'tenant.assets', 'tenant.maintenance'],
        };
    }

    public function can(string $permission): bool
    {
        foreach ($this->permissions() as $p) {
            if ($p === $permission || (str_ends_with($p, '.*') && str_starts_with($permission, substr($p, 0, -1)))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Authority rank used by maker/checker (REQ-0069: approved by another user with higher authority).
     * Manager-level users rank above staff. See QUESTIONS.md Q10 for entries made by top-rank users.
     */
    public function authority(): int
    {
        return match ($this) {
            self::Manager, self::InstitutionalOwner => 3,
            self::Accountant, self::LeasingOfficer, self::MaintenanceSupervisor => 2,
            self::Owner => 3,
            default => 0,
        };
    }

    /** Manager and owner must use two-factor authentication (REQ-0506, REQ-0370). */
    public function requiresTwoFactor(): bool
    {
        return in_array($this, [self::Manager, self::Owner, self::InstitutionalOwner], true);
    }
}
