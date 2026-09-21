<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Surface;
use App\Tenancy\CurrentSurface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Livewire component may only be driven from the surface it belongs to.
 *
 * Livewire's update endpoint takes a component by SNAPSHOT, and the snapshot's
 * checksum covers the snapshot — not the host it is posted to. Each surface
 * registers its own update route, but nothing otherwise stopped a snapshot
 * obtained on one surface from being replayed against another's endpoint, and
 * the surface's own route middleware (the oversight role gate, the tenant
 * membership gate) is attached to the PAGE routes, not to the component.
 *
 * The sharpest edge was the apex route: it is the one Livewire generates its
 * client URI from, so it must stay parameter-free and cannot carry `auth` —
 * the public portal has a Livewire component of its own. That left an
 * unauthenticated endpoint that would happily hydrate an oversight component,
 * which reads its authority from the GLOBAL permission team (the container
 * default) rather than from a bound tenant.
 *
 * The rule is therefore structural rather than permission-based: a component's
 * namespace must match the surface the request arrived on. `shared.*`
 * components are the one crossover — they render inside both app shells — and
 * are deliberately NOT allowed on the public portal, which has no signed-in
 * viewer to render them for.
 */
class MatchLivewireComponentToSurface
{
    public function handle(Request $request, Closure $next): Response
    {
        foreach ($this->componentNames($request) as $name) {
            abort_unless($this->allowed($name), 404);
        }

        return $next($request);
    }

    private function allowed(string $name): bool
    {
        $surface = app(CurrentSurface::class)->get();

        $namespace = str_contains($name, '.') ? strstr($name, '.', true) : $name;

        return match ($surface) {
            Surface::Portal => $namespace === Surface::Portal->value,
            Surface::Oversight => in_array($namespace, [Surface::Oversight->value, 'shared'], true),
            Surface::Tenant => in_array($namespace, [Surface::Tenant->value, 'shared'], true),
        };
    }

    /**
     * The component names in this update payload. Livewire batches, so there
     * may be several; a payload we cannot read is left to Livewire to reject
     * on its own terms rather than guessed at here.
     *
     * @return list<string>
     */
    private function componentNames(Request $request): array
    {
        $components = $request->input('components');

        if (! is_array($components)) {
            return [];
        }

        $names = [];

        foreach ($components as $component) {
            $snapshot = is_array($component) ? ($component['snapshot'] ?? null) : null;

            if (! is_string($snapshot)) {
                continue;
            }

            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($snapshot, associative: true);
            $name = is_array($decoded) && is_array($decoded['memo'] ?? null)
                ? ($decoded['memo']['name'] ?? null)
                : null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
