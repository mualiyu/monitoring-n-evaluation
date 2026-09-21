<?php

declare(strict_types=1);

namespace App\Actions\Lifecycle\Concerns;

use App\Actions\Documents\AttachGeneratedDocument;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Renders a lifecycle artifact to PDF and files it in the record's vault.
 *
 * Shared by the two issuing Actions because the rule they must not diverge on
 * is the WHITE-LABEL one: every name, mark and currency on a generated
 * government document comes from the tenant record and
 * `config('platform.instance.*')`. A template that reached for a constant, or
 * an Action that passed a hard-coded heading, would put one client's identity
 * on another client's certificate — and it would be printed and signed before
 * anyone noticed.
 *
 * The bytes go through App\Actions\Documents\AttachGeneratedDocument, so a
 * generated artifact lands under the same vault rules as an upload: private
 * disk, generated filename, collection role list, mime/size ceiling, audit
 * line (rules/security.md §Uploads).
 */
trait RendersLifecyclePdf
{
    /**
     * @param  array<string, mixed>  $data  view data, merged with the branding block
     */
    protected function attachRenderedPdf(
        Model&HasMedia $record,
        string $collection,
        string $view,
        array $data,
        string $title,
        User $actor,
        ?Tenant $tenant,
    ): Media {
        $pdf = Pdf::loadView($view, [
            ...$data,
            'brand' => $this->brandingBlock($tenant),
        ])->setPaper('a4');

        return app(AttachGeneratedDocument::class)(
            $record,
            $collection,
            $pdf->output(),
            $title,
            $actor,
        );
    }

    /**
     * Everything the letterhead is allowed to know. Read here rather than in
     * the Blade so the template has no way to reach config at all.
     *
     * @return array{entity: string|null, instance: string, short_name: string, currency: string, timezone: string}
     */
    protected function brandingBlock(?Tenant $tenant): array
    {
        return [
            // The issuing entity is the workspace itself — DATA, never a name
            // typed into this codebase.
            'entity' => $tenant?->name,
            'instance' => (string) config('platform.instance.name'),
            'short_name' => (string) config('platform.instance.short_name'),
            'currency' => (string) config('platform.instance.currency'),
            'timezone' => (string) config('platform.instance.timezone'),
        ];
    }
}
