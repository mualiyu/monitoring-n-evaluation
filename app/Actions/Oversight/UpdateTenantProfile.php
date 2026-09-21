<?php

declare(strict_types=1);

namespace App\Actions\Oversight;

use App\Enums\TenantType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Edits a workspace record and its branding overrides.
 *
 * THE SLUG IS NOT EDITABLE HERE, deliberately. It is a live DNS label: every
 * bookmark, every emailed report link, every signed download URL and every
 * session cookie in that ministry is scoped to it. Renaming a workspace's
 * display name is a Tuesday; renaming its subdomain is a migration with a
 * redirect plan, and it does not belong behind a text input on an edit form.
 *
 * BRANDING. `branding` is the JSON the design system already reads:
 * `display_name` and `logo_url` feed <x-ui.brand>, and `tokens` is the
 * allow-listed design-token override layouts/partials/head.blade.php emits as
 * CSS custom properties. One picked colour writes the three brand steps the
 * semantic tokens point at (--brand-500/600/700), so a state gets a coherent
 * ramp rather than one recoloured button.
 *
 * THE LOGO IS NOT A DOCUMENT-VAULT RECORD. Every vault collection lands on the
 * private disk behind a signed 15-minute URL, which is right for an award
 * letter and impossible for a crest that must render in an <img> on every page
 * and, in Phase 3, on the anonymous public portal. It goes to the public disk
 * through medialibrary instead — with the upload controls that actually matter
 * kept: the mime type is sniffed and allow-listed server-side, the size is
 * capped, and the name on disk is generated rather than the uploader's.
 */
class UpdateTenantProfile
{
    /** Kilobytes. A crest is a crest; anything larger is a scan of one. */
    private const LOGO_MAX_KB = 2048;

    /**
     * @param  array{name?: string, short_name?: string|null, type?: TenantType|string, sector_id?: int|null, contact_name?: string|null, contact_email?: string|null, contact_phone?: string|null, display_name?: string|null, brand_color?: string|null}  $attributes
     *
     * @throws ValidationException when the logo fails the server-side rules
     */
    public function __invoke(User $actor, Tenant $tenant, array $attributes, ?UploadedFile $logo = null): Tenant
    {
        if (! $actor->holdsGlobalPermission('tenants.manage')) {
            throw new AuthorizationException('Editing a workspace requires state-level tenants.manage authority.');
        }

        $type = $attributes['type'] ?? $tenant->type;
        $type = $type instanceof TenantType ? $type : (TenantType::tryFrom((string) $type) ?? $tenant->type);

        $tenant->fill([
            'name' => trim((string) ($attributes['name'] ?? $tenant->name)),
            'short_name' => $this->nullableString($attributes['short_name'] ?? $tenant->short_name),
            'type' => $type,
            'sector_id' => $attributes['sector_id'] ?? $tenant->sector_id,
            'contact_name' => $this->nullableString($attributes['contact_name'] ?? $tenant->contact_name),
            'contact_email' => $this->nullableString($attributes['contact_email'] ?? $tenant->contact_email),
            'contact_phone' => $this->nullableString($attributes['contact_phone'] ?? $tenant->contact_phone),
        ]);

        $branding = is_array($tenant->branding) ? $tenant->branding : [];

        if (array_key_exists('display_name', $attributes)) {
            $displayName = $this->nullableString($attributes['display_name']);
            $displayName === null
                ? $branding = array_diff_key($branding, ['display_name' => null])
                : $branding['display_name'] = $displayName;
        }

        if (array_key_exists('brand_color', $attributes)) {
            $branding = $this->applyBrandColour($branding, $this->nullableString($attributes['brand_color']));
        }

        $tenant->branding = $branding === [] ? null : $branding;
        $tenant->save();

        if ($logo instanceof UploadedFile) {
            $this->storeLogo($tenant, $logo);
        }

        activity('tenancy')
            ->causedBy($actor)
            ->performedOn($tenant)
            ->withProperties(['attributes' => ['branding' => $tenant->branding]])
            ->log('tenant.profile_updated');

        return $tenant;
    }

    /**
     * Server-side upload rules, then a generated filename. Mirrors what
     * App\Actions\Documents\AttachDocument does for vault records — the
     * destination differs, the discipline does not.
     */
    private function storeLogo(Tenant $tenant, UploadedFile $logo): void
    {
        /** @var list<string> $imageTypes */
        $imageTypes = config('documents.images', ['image/jpeg', 'image/png', 'image/webp']);

        Validator::make(['logo' => $logo], ['logo' => [
            'file',
            'max:'.self::LOGO_MAX_KB,
            'mimetypes:'.implode(',', $imageTypes),
        ]], [], ['logo' => __('logo')])->validate();

        $extension = $logo->guessExtension();
        $extension = is_string($extension) && preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : 'bin';

        $media = $tenant->addMedia($logo->getRealPath())
            ->usingFileName(Str::ulid()->toBase32().'.'.$extension)
            ->usingName($tenant->name.' '.__('logo'))
            ->toMediaCollection(Tenant::LOGO_COLLECTION, 'public');

        $branding = is_array($tenant->branding) ? $tenant->branding : [];
        $branding['logo_url'] = $media->getUrl();

        $tenant->branding = $branding;
        $tenant->save();
    }

    /**
     * One picked colour → the three brand steps the semantic tokens resolve
     * through. --brand-600 is `--brand-interactive` and the focus ring,
     * --brand-700 is `--brand-strong` and `--brand-ink`, --brand-500 is the
     * dark-theme interactive step; leaving the ramp half-overridden is how a
     * re-skin ends up with a green button and a blue focus ring.
     *
     * A null colour clears the override and the platform palette returns.
     *
     * @param  array<string, mixed>  $branding
     * @return array<string, mixed>
     */
    private function applyBrandColour(array $branding, ?string $hex): array
    {
        $tokens = is_array($branding['tokens'] ?? null) ? $branding['tokens'] : [];
        $tokens = array_diff_key($tokens, array_flip(['--brand-500', '--brand-600', '--brand-700', '--brand-soft']));

        if ($hex === null || preg_match('/^#[0-9a-fA-F]{6}$/', $hex) !== 1) {
            unset($branding['brand_color']);
            $tokens === [] ? $branding = array_diff_key($branding, ['tokens' => null]) : $branding['tokens'] = $tokens;

            return $branding;
        }

        $hex = Str::lower($hex);

        $branding['brand_color'] = $hex;
        $branding['tokens'] = [
            ...$tokens,
            '--brand-500' => $this->shift($hex, 1.18),
            '--brand-600' => $hex,
            '--brand-700' => $this->shift($hex, 0.82),
            '--brand-soft' => $this->shift($hex, 2.60),
        ];

        return $branding;
    }

    /**
     * Scale a hex colour towards white (factor > 1) or black (factor < 1),
     * clamped per channel. Not a perceptual colour space — the platform's own
     * ramp is oklch — but it is deterministic, needs no dependency, and only
     * ever produces a valid six-digit hex, which is what the head partial's
     * allow-list will accept.
     */
    private function shift(string $hex, float $factor): string
    {
        $shifted = '#';

        foreach ([1, 3, 5] as $offset) {
            $channel = (int) hexdec(substr($hex, $offset, 2));
            $channel = (int) round($channel * $factor);
            $shifted .= str_pad(dechex(max(0, min(255, $channel))), 2, '0', STR_PAD_LEFT);
        }

        return $shifted;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
