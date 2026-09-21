<?php

namespace App\Enums;

/**
 * How a piece of stakeholder feedback reached the platform.
 *
 * The portal form is only one of them: a town-hall meeting, a phone call to
 * the secretariat or a note handed to a field monitor are all legitimate
 * community feedback, and the register is worth far less if it only holds the
 * submissions that came from people with a smartphone. Everything except
 * `portal` is entered by staff on their behalf.
 */
enum FeedbackChannel: string
{
    case Portal = 'portal';
    case Email = 'email';
    case Phone = 'phone';
    case WalkIn = 'walk_in';
    case TownHall = 'town_hall';
    case FieldVisit = 'field_visit';

    /** The only channel an anonymous member of the public may submit through. */
    public function isPublic(): bool
    {
        return $this === self::Portal;
    }

    public function label(): string
    {
        return match ($this) {
            self::Portal => __('Public portal'),
            self::Email => __('Email'),
            self::Phone => __('Telephone'),
            self::WalkIn => __('Walk-in'),
            self::TownHall => __('Town hall meeting'),
            self::FieldVisit => __('Field visit'),
        };
    }

    /** @return array<string, string> value => label, for a filter select. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
