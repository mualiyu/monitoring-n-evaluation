<?php

/**
 * The money boundary (projects-module.md §1.1). Storage is decimal(18,2) so an
 * auditor reading the database sees 4500000.00; PHP arithmetic is integer kobo
 * so no float ever touches a naira.
 *
 * The float-rejection branch is the single most important line in the money
 * implementation: an inexact naira on a government dashboard is an audit
 * finding, not a rounding detail. It is tested from both directions — the
 * declared write path and the "a float arrived from a form/import/JSON anyway"
 * path the cast is deliberately typed `mixed` to catch.
 *
 * No container: Money::format() is exercised with an explicit currency so the
 * config fallback is not needed here (it is covered in the Feature money test).
 */

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

function moneyCarrier(): Model
{
    return new class extends Model
    {
        protected $table = 'money_carriers';
    };
}

it('rejects a float assigned to a money attribute instead of rounding it', function () {
    expect(fn () => (new MoneyCast)->set(moneyCarrier(), 'sum', 45000.30, []))
        ->toThrow(InvalidArgumentException::class, 'Float assigned to money attribute [sum]');
});

it('rejects a float even when it looks like a whole number of naira', function () {
    expect(fn () => (new MoneyCast)->set(moneyCarrier(), 'budget_allocation', 45000.0, []))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects values that are neither money, minor units nor a decimal string', function (mixed $value) {
    expect(fn () => (new MoneyCast)->set(moneyCarrier(), 'sum', $value, []))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'an array' => [['amount' => 1]],
    'a boolean' => [true],
    'an unparseable string' => ['forty-five thousand'],
    'a string with a stray symbol' => ['₦45,000.00'],
]);

it('accepts the three declared write forms and stores them all as the same decimal', function (mixed $value) {
    expect((new MoneyCast)->set(moneyCarrier(), 'sum', $value, []))->toBe('45000.30');
})->with([
    'minor units' => [4_500_030],
    'a decimal string' => ['45000.30'],
    'a grouped decimal string' => ['45,000.30'],
    'a Money value object' => [fn () => Money::fromMinor(4_500_030)],
]);

it('writes null through untouched, because "no figure yet" is not zero', function () {
    expect((new MoneyCast)->set(moneyCarrier(), 'contract_value_total', null, []))->toBeNull()
        ->and((new MoneyCast)->get(moneyCarrier(), 'contract_value_total', null, []))->toBeNull();
});

it('normalises whatever the database driver hands back into the same Money', function (mixed $fromDriver) {
    $money = (new MoneyCast)->get(moneyCarrier(), 'sum', $fromDriver, []);

    expect($money)->toBeInstanceOf(Money::class)
        ->and($money->minor())->toBe(4_500_000_00);
})->with([
    'MySQL DECIMAL as a string' => ['4500000.00'],
    'SQLite NUMERIC as an int' => [4_500_000],
    'SQLite NUMERIC as a float' => [4_500_000.00],
    'an already-cast Money' => [fn () => Money::fromMinor(4_500_000_00)],
]);

it('keeps the kobo when the driver returns a fractional float', function () {
    expect((new MoneyCast)->get(moneyCarrier(), 'sum', 45000.30, [])->minor())->toBe(4_500_030);
});

it('refuses to read a money column back as something that is not a number', function () {
    expect(fn () => (new MoneyCast)->get(moneyCarrier(), 'sum', ['4500000.00'], []))
        ->toThrow(InvalidArgumentException::class, 'Unreadable money value for attribute [sum]');
});

it('round-trips a value through the cast without losing a kobo', function (string $decimal) {
    $stored = (new MoneyCast)->set(moneyCarrier(), 'sum', $decimal, []);
    $read = (new MoneyCast)->get(moneyCarrier(), 'sum', $stored, []);

    expect($read->toDecimalString())->toBe($decimal);
})->with([
    ['0.00'],
    ['0.01'],
    ['45000.30'],
    ['4500000.00'],
    ['999999999999999.99'], // the top of decimal(18,2)
    ['-12500.75'],          // a downward contract variation
]);

it('parses decimal strings into exact minor units', function (string $input, int $minor) {
    expect(Money::fromDecimalString($input)->minor())->toBe($minor);
})->with([
    'plain naira' => ['4500000', 450_000_000],
    'two decimal places' => ['4500000.00', 450_000_000],
    'one decimal place' => ['0.5', 50],
    'grouped thousands' => ['4,500,000.25', 450_000_025],
    'a leading plus' => ['+12.34', 1234],
    'a negative variation' => ['-12.50', -1250],
]);

it('rounds a third decimal place rather than truncating it', function (string $input, int $minor) {
    expect(Money::fromDecimalString($input)->minor())->toBe($minor);
})->with([
    'rounds up at five' => ['12.505', 1251],
    'rounds up above five' => ['12.999', 1300],
    'rounds down below five' => ['12.504', 1250],
]);

it('adds and subtracts money without ever leaving the integers', function () {
    $award = Money::fromDecimalString('4500000.00');
    $variation = Money::fromDecimalString('250000.50');

    expect($award->plus($variation)->toDecimalString())->toBe('4750000.50')
        ->and($award->minus($variation)->toDecimalString())->toBe('4249999.50')
        ->and($award->plus($variation)->minus($variation)->equals($award))->toBeTrue();
});

it('sums a list of money values exactly, where floats would drift', function () {
    $sum = array_reduce(
        array_fill(0, 10, Money::fromDecimalString('0.10')),
        fn (Money $carry, Money $item) => $carry->plus($item),
        Money::zero(),
    );

    expect($sum->toDecimalString())->toBe('1.00')
        ->and($sum->minor())->toBe(100);
});

it('reports a percentage as a ratio and never as money', function () {
    $spent = Money::fromDecimalString('2250000.00');
    $total = Money::fromDecimalString('4500000.00');

    expect($spent->percentageOf($total))->toBe(50.0)
        ->and($spent->percentageOf(Money::zero()))->toBeNull(); // no division by zero, no false 0%
});

it('formats for display with grouped digits and the instance currency symbol', function () {
    expect(Money::fromDecimalString('4500000.00')->format('NGN'))->toBe('₦4,500,000.00')
        ->and(Money::fromDecimalString('-12500.75')->format('NGN'))->toBe('-₦12,500.75')
        ->and(Money::fromDecimalString('999.99')->format('USD'))->toBe('$999.99')
        ->and(Money::fromDecimalString('1000.00')->format('XOF'))->toBe('XOF 1,000.00');
});

it('is immutable — arithmetic returns a new value and leaves the original alone', function () {
    $original = Money::fromMinor(1000);
    $increased = $original->plus(Money::fromMinor(500));

    expect($original->minor())->toBe(1000)
        ->and($increased->minor())->toBe(1500)
        ->and($original->equals($increased))->toBeFalse();
});

it('refuses to parse a money value that is not a number at all', function (string $input) {
    expect(fn () => Money::fromDecimalString($input))
        ->toThrow(InvalidArgumentException::class, 'Unparseable money value');
})->with([
    'empty' => [''],
    'words' => ['four million'],
    'a currency symbol' => ['₦4500000.00'],
    'two decimal points' => ['4500.00.00'],
    'a scientific float' => ['4.5e6'],
]);

/*
|--------------------------------------------------------------------------
| Parser edges (migration review §7)
|--------------------------------------------------------------------------
*/

it('rejects a value wider than the column instead of saturating silently', function () {
    // (int) on 20 digits saturates at PHP_INT_MAX and would store a number
    // nobody typed — a money parser without a length guard is a quiet lie.
    expect(fn () => Money::fromDecimalString('99999999999999999999.99'))
        ->toThrow(InvalidArgumentException::class, 'exceeds the 16-digit range');
});

it('accepts the widest value decimal(18,2) can actually hold', function () {
    expect(Money::fromDecimalString('9999999999999999.99')->toDecimalString())
        ->toBe('9999999999999999.99');
});

it('ignores leading zeros when measuring the width', function () {
    expect(Money::fromDecimalString('0000000000000000000012.50')->minor())->toBe(1250);
});

it('rounds a negative amount by magnitude, exactly as it rounds a positive one', function () {
    // Downward contract variations are where negatives come from, and the two
    // directions must not round differently: -12.505 → -12.51, like 12.505 → 12.51.
    expect(Money::fromDecimalString('-12.505')->minor())->toBe(-1251)
        ->and(Money::fromDecimalString('-12.504')->minor())->toBe(-1250)
        ->and(Money::fromDecimalString('12.505')->minor())->toBe(1251);
});
