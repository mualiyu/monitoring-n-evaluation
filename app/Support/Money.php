<?php

namespace App\Support;

use InvalidArgumentException;
use Stringable;

/**
 * Money as an immutable value object holding **integer minor units** (kobo).
 *
 * Storage stays `decimal(18,2)` — auditors, DBAs and Excel exports read this
 * database directly and `4500000.00` is unambiguous where `450000000` kobo
 * invites a factor-of-100 error. PHP-side arithmetic stays integer, so no
 * float ever touches a naira: floats are rejected, not converted.
 *
 * Currency is an instance-level concern (`platform.instance.currency`) — the
 * platform is single-currency per deployment, so amounts carry no currency tag.
 */
final readonly class Money implements Stringable
{
    /** Minor units per major unit (100 kobo = ₦1). */
    public const SCALE = 100;

    /** Symbols for the currencies a deployment may be configured with. */
    private const SYMBOLS = [
        'NGN' => '₦',
        'USD' => '$',
        'GBP' => '£',
        'EUR' => '€',
    ];

    private function __construct(private int $minor) {}

    public static function fromMinor(int $minor): self
    {
        return new self($minor);
    }

    /**
     * The widest integer part `decimal(18,2)` can hold: 18 digits of precision
     * minus the 2 kobo places. Anything longer never came from this database,
     * so it is a parser error, not a value — and rejecting it here is what
     * stops (int) casting from saturating silently at PHP_INT_MAX
     * (migration review §7).
     */
    private const MAX_INTEGER_DIGITS = 16;

    /**
     * Parse a decimal representation ("4500000.00", "4,500,000", "-12.50").
     * More than two decimal places round half **away from zero** — the
     * magnitude is what rounds, so -12.505 becomes -12.51 exactly as 12.505
     * becomes 12.51. This is the only rounding point in the system and it
     * happens on digits, never on a float.
     */
    public static function fromDecimalString(string $amount): self
    {
        $normalized = str_replace([',', ' ', "\u{00A0}"], '', trim($amount));

        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException("Unparseable money value [{$amount}].");
        }

        if (strlen(ltrim($matches[2], '0')) > self::MAX_INTEGER_DIGITS) {
            throw new InvalidArgumentException(
                "Money value [{$amount}] exceeds the ".self::MAX_INTEGER_DIGITS.'-digit range of a decimal(18,2) column.'
            );
        }

        $fraction = $matches[3] ?? '';
        $minor = (int) $matches[2] * self::SCALE + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        if (isset($fraction[2]) && $fraction[2] >= '5') {
            $minor++;
        }

        return new self($matches[1] === '-' ? -$minor : $minor);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function minor(): int
    {
        return $this->minor;
    }

    public function plus(self $other): self
    {
        return new self($this->minor + $other->minor);
    }

    public function minus(self $other): self
    {
        return new self($this->minor - $other->minor);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor;
    }

    /**
     * This amount as a percentage of $total, rounded to 2 dp — null when the
     * total is zero (no meaningful ratio, and never a division by zero).
     *
     * The result is a *ratio*, not money: it is the one place a division
     * appears, and no money value is ever produced from it.
     */
    public function percentageOf(self $total): ?float
    {
        if ($total->minor === 0) {
            return null;
        }

        return round($this->minor * 100 / $total->minor, 2);
    }

    /** Database representation: a plain decimal string, always 2 dp. */
    public function toDecimalString(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $absolute = abs($this->minor);

        return $sign.intdiv($absolute, self::SCALE).'.'.str_pad((string) ($absolute % self::SCALE), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Display representation with currency symbol and thousands separators
     * (₦4,500,000.00). Grouping is done on digits — number_format() would take
     * the value through a float.
     */
    public function format(?string $currency = null): string
    {
        $currency ??= (string) config('platform.instance.currency');
        $symbol = self::SYMBOLS[$currency] ?? $currency.' ';

        $sign = $this->minor < 0 ? '-' : '';
        $absolute = abs($this->minor);
        $whole = (string) intdiv($absolute, self::SCALE);
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return $sign.$symbol.$grouped.'.'.str_pad((string) ($absolute % self::SCALE), 2, '0', STR_PAD_LEFT);
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }
}
