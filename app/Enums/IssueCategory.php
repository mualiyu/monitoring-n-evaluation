<?php

namespace App\Enums;

/**
 * What KIND of thing is blocking the work (plan §4 — the challenges register;
 * the Nasarawa lifecycle's "challenges & mitigation" on every monthly return).
 *
 * The list is closed on purpose. A free-text "problem type" produces forty
 * spellings of "no money" across forty MDAs and makes the one question the
 * state actually asks of this register — *what keeps stopping our projects* —
 * unanswerable. `Other` exists so nothing is unrecordable, and a category that
 * fills up with `other` is the signal to add a case here.
 */
enum IssueCategory: string
{
    case Funding = 'funding';
    case Access = 'access';
    case Security = 'security';
    case Weather = 'weather';
    case Design = 'design';
    case ContractorPerformance = 'contractor_performance';
    case Community = 'community';
    case Regulatory = 'regulatory';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Funding => __('Funding / cash release'),
            self::Access => __('Site access / right of way'),
            self::Security => __('Security'),
            self::Weather => __('Weather'),
            self::Design => __('Design / specification'),
            self::ContractorPerformance => __('Contractor performance'),
            self::Community => __('Community / stakeholder'),
            self::Regulatory => __('Regulatory / approvals'),
            self::Other => __('Other'),
        };
    }

    /**
     * An icon per category, so the register reads at a glance and a status is
     * never carried by colour alone (rules/ui-design-system.md).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Funding => 'banknotes',
            self::Access => 'map-pin',
            self::Security => 'shield-check',
            self::Weather => 'globe',
            self::Design => 'clipboard-check',
            self::ContractorPerformance => 'building-office',
            self::Community => 'chat-bubble',
            self::Regulatory => 'document-text',
            self::Other => 'information-circle',
        };
    }

    /**
     * value => label, for <x-ui.form.select :options="…">.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
