<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * The one reader of `config/documents.php`.
 *
 * Every upload in this platform is a government record, so three questions
 * have to be answered identically everywhere a file is accepted: which mime
 * types, how large, and who may put a file into THIS collection. Answering
 * them in each Livewire component is how a photos-only evidence collection
 * quietly starts accepting executables on one screen.
 *
 * `documents.upload` (the permission) only says a user may upload *something*.
 * The per-collection role list below says *what* — a Consultant may add report
 * evidence and never a contract instrument.
 */
class DocumentCollections
{
    /**
     * @return array<string, mixed>
     */
    public function definition(string $collection): array
    {
        /** @var array<string, array<string, mixed>> $collections */
        $collections = config('documents.collections', []);

        return $collections[$collection] ?? [];
    }

    public function exists(string $collection): bool
    {
        return $this->definition($collection) !== [];
    }

    public function label(string $collection): string
    {
        $label = $this->definition($collection)['label'] ?? $collection;

        return __(is_string($label) ? $label : $collection);
    }

    public function isSingle(string $collection): bool
    {
        return (bool) ($this->definition($collection)['single'] ?? false);
    }

    public function maxKilobytes(string $collection): int
    {
        $max = $this->definition($collection)['max_kb'] ?? 10240;

        return is_numeric($max) ? (int) $max : 10240;
    }

    /**
     * The server-side mime allow-list. `images_only` collections (site photos,
     * inspection evidence) never accept a document: a PDF in a photo gallery
     * is a sign someone is filing paperwork as proof of a visit.
     *
     * @return list<string>
     */
    public function mimeTypes(string $collection): array
    {
        /** @var list<string> $images */
        $images = config('documents.images', []);

        if ($this->definition($collection)['images_only'] ?? false) {
            return $images;
        }

        /** @var list<string> $papers */
        $papers = config('documents.papers', []);

        return [...$papers, ...$images];
    }

    /**
     * Laravel validation rules for the upload field itself. `mimetypes` (not
     * `mimes`) so the check is on the sniffed type rather than the extension
     * the uploader chose.
     *
     * @return list<string>
     */
    public function validationRules(string $collection): array
    {
        return [
            'file',
            'max:'.$this->maxKilobytes($collection),
            'mimetypes:'.implode(',', $this->mimeTypes($collection)),
        ];
    }

    /**
     * Whether this user may add to this collection: the platform-wide upload
     * permission AND the collection's own role list (null = any uploader).
     */
    public function canUpload(User $user, string $collection): bool
    {
        if (! $this->exists($collection)) {
            return false;
        }

        if (! $user->can('documents.upload') && ! $user->holdsGlobalPermission('documents.upload')) {
            return false;
        }

        $roles = $this->definition($collection)['roles'] ?? null;

        if (! is_array($roles)) {
            return true;
        }

        return $user->hasAnyRole(array_map(
            fn (Role $role): string => $role->value,
            array_filter($roles, fn (mixed $role): bool => $role instanceof Role),
        ));
    }

    /**
     * The `accept` attribute for the file input — a convenience for the
     * browser, never a control. The server list above is the control.
     */
    public function acceptAttribute(string $collection): string
    {
        return implode(',', $this->mimeTypes($collection));
    }
}
