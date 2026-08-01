<?php

namespace App\Enums;

/**
 * Lifecycle of a single contract (award → execution → close-out). Independent
 * of the project lifecycle: a project may carry several contracts at once.
 */
enum ContractStatus: string
{
    case Awarded = 'awarded';
    case Active = 'active';
    case Completed = 'completed';
    case Terminated = 'terminated';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Terminated;
    }

    public function label(): string
    {
        return match ($this) {
            self::Awarded => __('Awarded'),
            self::Active => __('Active'),
            self::Completed => __('Completed'),
            self::Terminated => __('Terminated'),
        };
    }
}
