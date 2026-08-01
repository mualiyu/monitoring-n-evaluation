<?php

namespace App\Enums;

/**
 * Platform roles. Tenant-scoped roles are assigned with a tenant team id;
 * oversight/global roles are assigned with a null team id.
 */
enum Role: string
{
    // Oversight surface (tenant_id null)
    case SuperAdmin = 'super-admin';
    case StateAdmin = 'state-admin';           // MEP&B secretariat / Director M&E
    case ExecutiveViewer = 'executive-viewer'; // Governor's office, House committee
    case DataQualityReviewer = 'data-quality-reviewer'; // e.g. Bureau of Statistics

    // Tenant surface (scoped to an MDA)
    case MdaAdmin = 'mda-admin';               // Permanent Sec / Director of M&E
    case MeOfficer = 'me-officer';             // M&E / focal officer
    case Consultant = 'consultant';            // contractor / consultant
    case FieldMonitor = 'field-monitor';       // inspector

    /** @return list<self> */
    public static function oversightRoles(): array
    {
        return [self::SuperAdmin, self::StateAdmin, self::ExecutiveViewer, self::DataQualityReviewer];
    }

    /** @return list<self> */
    public static function tenantRoles(): array
    {
        return [self::MdaAdmin, self::MeOfficer, self::Consultant, self::FieldMonitor];
    }

    /**
     * The invitation chain (PROJECT_PLAN §2): oversight admins provision
     * oversight roles and MDA admins; MDA admins staff their own workspace
     * only. No tenant role can invite upward or sideways into admin.
     *
     * @param  list<self>  $inviterRoles
     * @return list<self>
     */
    public static function invitableBy(array $inviterRoles): array
    {
        $invitable = [];

        foreach ($inviterRoles as $role) {
            $invitable = [...$invitable, ...match ($role) {
                self::SuperAdmin, self::StateAdmin => [
                    self::StateAdmin, self::ExecutiveViewer, self::DataQualityReviewer, self::MdaAdmin,
                ],
                self::MdaAdmin => [self::MeOfficer, self::Consultant, self::FieldMonitor],
                default => [],
            }];
        }

        return array_values(array_unique($invitable, SORT_REGULAR));
    }

    /**
     * Whether holding this role makes two-factor authentication mandatory.
     */
    public function requiresTwoFactor(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::StateAdmin, self::MdaAdmin => true,
            default => false,
        };
    }

    /**
     * 2FA enrolment grace period in days for this role. The platform
     * operator gets none — they enrol before anything else.
     */
    public function twoFactorGraceDays(): int
    {
        return $this === self::SuperAdmin
            ? 0
            : (int) config('platform.auth.two_factor_grace_days');
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => __('Super Admin'),
            self::StateAdmin => __('State M&E Admin'),
            self::ExecutiveViewer => __('Executive Viewer'),
            self::DataQualityReviewer => __('Data Quality Reviewer'),
            self::MdaAdmin => __('MDA Admin'),
            self::MeOfficer => __('M&E Officer'),
            self::Consultant => __('Consultant'),
            self::FieldMonitor => __('Field Monitor'),
        };
    }
}
