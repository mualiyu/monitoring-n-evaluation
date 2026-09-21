<?php

namespace App\Support\Exporting;

use App\Models\Tenant;

/**
 * The print palette and identity for generated PDFs — white-label, and the
 * ONLY place in the export layer that names a colour.
 *
 * WHY THIS EXISTS AT ALL. The app's design tokens are OKLCH CSS custom
 * properties (resources/css/app.css). dompdf resolves neither: it has no
 * `var()` support worth relying on and no OKLCH parser, so a PDF template that
 * used the token system would render black on black. The design rule that
 * matters — "never type a raw hex colour in a Blade file" — is therefore kept
 * by putting the print fallbacks HERE, in one overridable place, and having
 * every template read them as values.
 *
 * WHITE-LABEL. No state, ministry or product name appears in a template.
 * Identity comes from instance config (`platform.instance.*`) and, for a
 * workspace-scoped artifact, from the tenant's own `branding` JSON — so a new
 * client is a configuration change, not a template fork.
 *
 * The defaults below are the neutral greys and the brand green of the shipped
 * placeholder palette, converted to sRGB. They are a FALLBACK: a deployment
 * that sets `branding.print` gets its own.
 */
final readonly class PdfTheme
{
    /**
     * @param  array<string, string>  $colors
     */
    private function __construct(
        public string $instanceName,
        public string $instanceShortName,
        public ?string $entityName,
        public string $currency,
        public array $colors,
    ) {}

    /**
     * The neutral print fallback. Every value is overridable per deployment
     * through a tenant's `branding.print` map; none of them is typed in a
     * Blade file.
     *
     * @return array<string, string>
     */
    public static function defaultColors(): array
    {
        return [
            'ink' => '#1f2723',          // body text
            'ink_muted' => '#5c6660',    // captions, table meta
            'ink_subtle' => '#8a938d',   // rules, footnotes
            'brand' => '#2f6b4f',        // headings, accents
            'brand_soft' => '#eaf2ed',   // table header fill, callouts
            'line' => '#d6dcd8',         // borders
            'surface' => '#ffffff',      // page
            'surface_sunken' => '#f5f7f6',
            'positive' => '#2f6b4f',
            'warning' => '#8a6516',
            'critical' => '#9b2c2c',
        ];
    }

    public static function for(?Tenant $tenant = null): self
    {
        $colors = self::defaultColors();

        /** @var array<string, mixed>|null $branding */
        $branding = $tenant?->branding;
        $print = $branding['print'] ?? null;

        if (is_array($print)) {
            foreach ($print as $key => $value) {
                // Only keys the templates actually read, and only values that
                // are plausible CSS colours. A branding blob is client-supplied
                // configuration; it does not get to inject arbitrary CSS into a
                // document the state signs.
                if (array_key_exists($key, $colors) && is_string($value) && self::isColor($value)) {
                    $colors[$key] = $value;
                }
            }
        }

        return new self(
            instanceName: (string) config('platform.instance.name'),
            instanceShortName: (string) config('platform.instance.short_name'),
            entityName: $tenant?->name,
            currency: (string) config('platform.instance.currency'),
            colors: $colors,
        );
    }

    public function color(string $key): string
    {
        return $this->colors[$key] ?? self::defaultColors()[$key] ?? $this->colors['ink'];
    }

    private static function isColor(string $value): bool
    {
        return preg_match('/^#[0-9a-f]{3,8}$/i', $value) === 1
            || preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*[\d.]+\s*)?\)$/i', $value) === 1;
    }
}
