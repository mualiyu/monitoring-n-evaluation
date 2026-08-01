<?php

/**
 * Static discipline sweep: dangerous tenancy operations are only allowed in
 * the sanctioned locations. This is the enforcement for rules/tenancy.md —
 * a new violation fails CI with the offending file:line in the message.
 */
const TENANCY_DISCIPLINE_RULES = [
    'withoutTenancy(' => [
        'app/Models/Concerns/BelongsToTenant.php',
        'app/Tenancy/Exceptions/', // exception guidance messages
        'app/Actions/Oversight/',
        'app/Livewire/Oversight/',
    ],
    '->bypass(' => [
        'app/Tenancy/',
        'app/Actions/Oversight/',
        'app/Livewire/Oversight/',
        'database/seeders/',
    ],
    'withoutGlobalScope' => [
        'app/Models/Concerns/BelongsToTenant.php',
    ],
    'setPermissionsTeamId(' => [
        'app/Tenancy/CurrentTenant.php',
        'app/Providers/TenancyServiceProvider.php',
        'database/seeders/RoleSeeder.php',
    ],
    '->assignRole(' => [
        'app/Actions/Iam/AssignRole.php',
    ],
    '->removeRole(' => [
        'app/Actions/Iam/RevokeTenantAccess.php',
    ],
    // The membership gate + invitations cannot use BelongsToTenant (they
    // decide/precede tenancy) — their explicit tenant_id filters are the
    // sanctioned exceptions, confined to the Iam actions and auth plumbing.
    "where('tenant_id'" => [
        'app/Actions/Iam/',
        'app/Http/Middleware/EnsureTenantMembership.php',
        'app/Http/Responses/LoginResponse.php',
    ],
    'TenantMembership::' => [
        'app/Actions/Iam/',
        'app/Http/Middleware/EnsureTenantMembership.php',
        'app/Http/Responses/LoginResponse.php',
    ],
    'Gate::before' => [],
    'saveQuietly(' => [],
    'insertQuietly(' => [],
    'insertGetId(' => [],
];

function tenancyDisciplineViolations(): array
{
    $root = dirname(__DIR__, 2);
    $violations = [];

    foreach (['app', 'database'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($root.'/', '', $file->getPathname());
            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $number => $line) {
                $trimmed = ltrim($line);

                // Comments may reference the patterns when documenting them.
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                    continue;
                }

                foreach (TENANCY_DISCIPLINE_RULES as $pattern => $allowedPaths) {
                    if (! str_contains($line, $pattern)) {
                        continue;
                    }

                    foreach ($allowedPaths as $allowed) {
                        if (str_starts_with($relative, $allowed)) {
                            continue 2;
                        }
                    }

                    $violations[] = sprintf('%s:%d uses "%s"', $relative, $number + 1, $pattern);
                }
            }
        }
    }

    return $violations;
}

it('confines dangerous tenancy operations to their sanctioned locations', function () {
    expect(tenancyDisciplineViolations())->toBeEmpty();
});
