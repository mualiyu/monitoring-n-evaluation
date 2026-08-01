<?php

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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

/*
|--------------------------------------------------------------------------
| Schema ↔ model sweep
|--------------------------------------------------------------------------
| The grep above polices what code *does*; this pair polices what the schema
| and the models *are*. A tenant-owned table whose model forgot the trait is
| an unscoped table — the leak the rules exist to prevent — and it must not be
| a human's job to notice (migration review §1).
*/

/** Tables carrying tenant_id that must NOT use the trait, and why. */
const TENANT_COLUMN_EXCEPTIONS = [
    // auth-surfaces.md §2.2 — membership is the gate that DECIDES tenancy;
    // a gate scoped by its own outcome is circular.
    'tenant_user' => 'the membership gate is read from an unbound context by design',
    // auth-surfaces.md §3.1 — tenant_id is nullable (oversight invitations)
    // and an invitation precedes any tenancy the invitee will have.
    'invitations' => 'invitations are pre-tenancy and may carry a null tenant_id',
];

/**
 * Tables whose migration defines a tenant_id column.
 *
 * @return list<string>
 */
function tablesWithTenantColumn(): array
{
    $tables = [];
    $current = null;

    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $path) {
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $trimmed = ltrim($line);

            // Comments discuss tenant_id constantly (usually to explain its
            // absence) — only real column definitions count.
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            if (preg_match("/Schema::(?:create|table)\('([a-z0-9_]+)'/", $line, $matches) === 1) {
                $current = $matches[1];
            }

            // Quoted, so created_by_tenant_id (provenance, not a scope key)
            // does not count as a tenancy column.
            if ($current !== null && str_contains($line, "'tenant_id'")) {
                $tables[$current] = true;
            }
        }

        $current = null;
    }

    return array_keys($tables);
}

/**
 * Every Eloquent model in app/Models, as table => [class, usesTrait].
 *
 * @return array<string, array{class: class-string, scoped: bool}>
 */
function modelsByTable(): array
{
    $models = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Models/*.php') ?: [] as $path) {
        $class = 'App\\Models\\'.basename($path, '.php');

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        $table = (new ReflectionClass($class))->getDefaultProperties()['table']
            ?? Str::snake(Str::pluralStudly(class_basename($class)));

        $models[$table] = [
            'class' => $class,
            'scoped' => in_array(
                BelongsToTenant::class,
                class_uses_recursive($class),
                true,
            ),
        ];
    }

    return $models;
}

it('gives every table with a tenant_id column a model that scopes itself', function () {
    $models = modelsByTable();

    $unscoped = array_values(array_filter(
        tablesWithTenantColumn(),
        fn (string $table) => isset($models[$table])
            && ! $models[$table]['scoped']
            && ! array_key_exists($table, TENANT_COLUMN_EXCEPTIONS),
    ));

    // toBe([]) rather than toBeEmpty(): the failure diff then names the table.
    expect($unscoped)->toBe([]);
});

it('gives every model that scopes itself a table with a tenant_id column', function () {
    $tables = tablesWithTenantColumn();

    $missingColumn = [];

    foreach (modelsByTable() as $table => $model) {
        if ($model['scoped'] && ! in_array($table, $tables, true)) {
            $missingColumn[] = $model['class'].' → '.$table;
        }
    }

    expect($missingColumn)->toBe([]);
});

it('keeps the tenant-column exception list to the two documented gate tables', function () {
    // Growing this list is a design decision, not a refactor: each entry is a
    // table the global scope cannot police.
    expect(array_keys(TENANT_COLUMN_EXCEPTIONS))->toBe(['tenant_user', 'invitations']);

    foreach (array_keys(TENANT_COLUMN_EXCEPTIONS) as $table) {
        expect(tablesWithTenantColumn())->toContain($table);
    }
});

it('finds the nine tenant-owned tables of the projects slice, all of them scoped', function () {
    $models = modelsByTable();

    foreach ([
        'projects', 'project_locations', 'project_funding_sources', 'contracts',
        'project_assignments', 'project_status_events', 'indicators',
        'indicator_targets', 'indicator_readings',
    ] as $table) {
        expect(tablesWithTenantColumn())->toContain($table)
            ->and($models[$table]['scoped'] ?? false)->toBeTrue();
    }
});

it('leaves global reference tables unscoped, as cross-MDA aggregation requires', function () {
    $tables = tablesWithTenantColumn();
    $models = modelsByTable();

    foreach (['sectors', 'funding_sources', 'lgas', 'wards', 'contractors'] as $table) {
        expect($tables)->not->toContain($table)
            ->and($models[$table]['scoped'] ?? false)->toBeFalse();
    }
});
