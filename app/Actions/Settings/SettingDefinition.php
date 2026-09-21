<?php

declare(strict_types=1);

namespace App\Actions\Settings;

/**
 * One configurable policy decision: what it is called, how it is validated,
 * how a form field turns into a stored value and back.
 *
 * A value object rather than an array because every settings screen, every
 * validator and every round-trip test reads the same five facts about a
 * setting, and an array shape that only PHPStan enforces is a shape nobody
 * reads. `group` and `key` are the settings table's composite key and the
 * config fallback path (`config("platform.{group}.{key}")`) at the same time —
 * that is what makes App\Support\SettingsRepository's three-step chain work
 * without a second lookup table.
 */
final readonly class SettingDefinition
{
    public const TYPE_INT = 'int';

    public const TYPE_BOOL = 'bool';

    public const TYPE_STRING = 'string';

    public const TYPE_TEXT = 'text';

    public const TYPE_SELECT = 'select';

    /** A comma-separated ladder of whole numbers, e.g. the reminder ladder. */
    public const TYPE_INTS = 'ints';

    /** A comma-separated list of labels, e.g. the evaluation criteria. */
    public const TYPE_STRINGS = 'strings';

    /**
     * @param  list<string>  $rules  extra validation beyond the type's own
     * @param  array<string, string>  $options  value => label, for TYPE_SELECT
     */
    public function __construct(
        public string $group,
        public string $key,
        public string $label,
        public string $hint,
        public string $type,
        public mixed $default,
        public bool $tenantOverridable,
        public array $rules = [],
        public array $options = [],
    ) {}

    public function id(): string
    {
        return $this->group.'.'.$this->key;
    }

    /**
     * Server-side rules for one submitted value. The type contributes the
     * shape; the definition contributes the policy bounds. Both always run —
     * a settings screen is an admin form, and an admin form is still input.
     *
     * @return list<string>
     */
    public function validationRules(): array
    {
        $base = match ($this->type) {
            self::TYPE_INT => ['required', 'integer'],
            self::TYPE_BOOL => ['boolean'],
            self::TYPE_SELECT => ['required', 'string'],
            self::TYPE_TEXT => ['nullable', 'string', 'max:2000'],
            self::TYPE_INTS, self::TYPE_STRINGS => ['nullable', 'string', 'max:500'],
            default => ['nullable', 'string', 'max:255'],
        };

        if ($this->type === self::TYPE_SELECT) {
            $base[] = 'in:'.implode(',', array_keys($this->options));
        }

        return [...$base, ...$this->rules];
    }

    /**
     * Form input → the value that goes into the settings row.
     *
     * The list types arrive from a text input as "7, 3, 1" and are stored as a
     * JSON array, because that is the shape SettingsRepository::ints() hands
     * the deadline engine. Doing the split here rather than in the component
     * is what keeps the oversight screen and the MDA override screen from
     * disagreeing about what "7,3,1" means.
     */
    public function fromForm(mixed $input): mixed
    {
        return match ($this->type) {
            self::TYPE_INT => (int) $input,
            self::TYPE_BOOL => filter_var($input, FILTER_VALIDATE_BOOL),
            self::TYPE_INTS => array_values(array_map(
                static fn (string $part): int => (int) trim($part),
                $this->splitList($input),
            )),
            self::TYPE_STRINGS => array_values(array_map(
                static fn (string $part): string => trim($part),
                $this->splitList($input),
            )),
            default => is_string($input) ? trim($input) : $input,
        };
    }

    /** The stored value rendered back into a form control. */
    public function toForm(mixed $value): string|bool
    {
        if ($this->type === self::TYPE_BOOL) {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        if (is_array($value)) {
            return implode(', ', array_map(
                static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
                $value,
            ));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /** A human sentence for the value, used beside an inherited setting. */
    public function display(mixed $value): string
    {
        if ($this->type === self::TYPE_BOOL) {
            return filter_var($value, FILTER_VALIDATE_BOOL) ? __('Yes') : __('No');
        }

        if ($this->type === self::TYPE_SELECT && is_scalar($value)) {
            return $this->options[(string) $value] ?? (string) $value;
        }

        $rendered = $this->toForm($value);

        return is_string($rendered) && $rendered !== '' ? $rendered : '—';
    }

    /**
     * @return list<string>
     */
    private function splitList(mixed $input): array
    {
        if (is_array($input)) {
            return array_values(array_map(
                static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
                $input,
            ));
        }

        if (! is_string($input) || trim($input) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $input)),
            static fn (string $part): bool => $part !== '',
        ));
    }
}
