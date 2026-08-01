<?php

namespace App\Tenancy;

use App\Enums\Surface;

/**
 * Container-scoped holder for which entry surface the current request landed
 * on. Bound by ResolveSurface (Fortify routes) and available to auth
 * responses so they can branch without re-parsing the host.
 */
class CurrentSurface
{
    private ?Surface $surface = null;

    public function set(Surface $surface): void
    {
        $this->surface = $surface;
    }

    public function get(): Surface
    {
        return $this->surface ?? Surface::Portal;
    }

    public function is(Surface $surface): bool
    {
        return $this->surface === $surface;
    }
}
