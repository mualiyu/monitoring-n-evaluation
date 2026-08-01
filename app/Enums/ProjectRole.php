<?php

namespace App\Enums;

/**
 * A user's role *on a project* (project_assignments). Distinct from the
 * platform Role enum: platform roles say what you may do in a workspace,
 * project roles say which projects you are accountable for.
 */
enum ProjectRole: string
{
    case Consultant = 'consultant';
    case FieldMonitor = 'field_monitor';
    case FocalOfficer = 'focal_officer';
    case Supervisor = 'supervisor';

    public function label(): string
    {
        return match ($this) {
            self::Consultant => __('Consultant'),
            self::FieldMonitor => __('Field Monitor'),
            self::FocalOfficer => __('Focal Officer'),
            self::Supervisor => __('Supervisor'),
        };
    }
}
