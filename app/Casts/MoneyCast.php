<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * decimal(18,2) column <-> Money value object.
 *
 * Accepted inputs on write: a Money, an int (**minor units** — kobo), or a
 * decimal string ("4500000.00"). A float is rejected outright rather than
 * silently rounded: an inexact naira on a government dashboard is an audit
 * finding, not a rounding detail.
 *
 * On read, MySQL hands back DECIMAL as a string. SQLite (the test connection)
 * gives NUMERIC columns back as int/float, so both are normalised through the
 * decimal parser — the driver quirk stops at this boundary and never reaches
 * domain code.
 *
 * @implements CastsAttributes<Money, Money|int|string>
 */
class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return match (true) {
            $value === null => null,
            $value instanceof Money => $value,
            is_int($value), is_string($value) => Money::fromDecimalString((string) $value),
            is_float($value) => Money::fromDecimalString(sprintf('%.2F', $value)),
            default => throw new InvalidArgumentException(
                'Unreadable money value for attribute ['.$key.']: '.get_debug_type($value).'.'
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $money = match (true) {
            $value === null => null,
            $value instanceof Money => $value,
            is_int($value) => Money::fromMinor($value),
            default => Money::fromDecimalString($this->decimalInput($key, $value)),
        };

        return $money?->toDecimalString();
    }

    /**
     * The runtime type boundary. Declared `mixed` deliberately: a float can
     * still arrive from a form, an import or a JSON payload however the
     * signature is typed, and it must be rejected rather than rounded.
     */
    private function decimalInput(string $key, mixed $value): string
    {
        if (is_float($value)) {
            throw new InvalidArgumentException(
                "Float assigned to money attribute [{$key}] — pass minor units (int), a decimal string, or a Money."
            );
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'Unsupported money value for attribute ['.$key.']: '.get_debug_type($value).'.'
            );
        }

        return $value;
    }
}
