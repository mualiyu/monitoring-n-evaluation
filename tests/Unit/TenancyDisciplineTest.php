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
    // Model::fresh() is newQueryWithoutScopes(), so it re-loads a row with the
    // TenantScope OFF — an unscoped cross-tenant read wearing innocuous
    // clothing. It shipped twice (the project edit screen and the report
    // review refresh), safe both times only because an authorize() happened to
    // run first. Re-query through the model instead:
    // `Model::query()->whereKey($m->getKey())->firstOrFail()` fails closed.
    '->fresh(' => [],
];

/**
 * Does this line use the guarded token?
 *
 * Substring matching is deliberately blunt — but blunt is not the same as
 * wrong. `TenantMembership::` as a plain substring also matches
 * `CheckTenantMembership::class`, which is a DIFFERENT class (the sanctioned
 * Iam action other modules are supposed to ask through), so the sweep was
 * failing the very call it exists to encourage.
 *
 * A left word boundary fixes exactly that and nothing else: a pattern that
 * starts with a word character must not be preceded by one. `TenantMembership::query()`
 * is still caught, `App\Models\TenantMembership::` is still caught (a
 * backslash is not a word character), and `CheckTenantMembership::class` is
 * correctly not. Patterns that start with `-`, `>` or `:` are unaffected.
 */
function tenancyPatternMatches(string $line, string $pattern): bool
{
    if (preg_match('/^\w/', $pattern) !== 1) {
        return str_contains($line, $pattern);
    }

    return preg_match('/(?<![A-Za-z0-9_])'.preg_quote($pattern, '/').'/', $line) === 1;
}

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
                    if (! tenancyPatternMatches($line, $pattern)) {
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

it('matches the guarded class itself and not a different class that ends with its name', function () {
    // The sweep is only as good as its matcher, so the matcher has its own
    // test: loosening it later to silence a false positive would have to
    // break this first.
    expect(tenancyPatternMatches('TenantMembership::query()->get();', 'TenantMembership::'))->toBeTrue()
        ->and(tenancyPatternMatches('return \App\Models\TenantMembership::query();', 'TenantMembership::'))->toBeTrue()
        ->and(tenancyPatternMatches('app(CheckTenantMembership::class)($user);', 'TenantMembership::'))->toBeFalse()
        // Patterns that begin with punctuation are untouched by the boundary.
        ->and(tenancyPatternMatches('$m->fresh();', '->fresh('))->toBeTrue()
        ->and(tenancyPatternMatches('$q->withoutTenancy();', 'withoutTenancy('))->toBeTrue()
        ->and(tenancyPatternMatches("\$q->where('tenant_id', 1);", "where('tenant_id'"))->toBeTrue();
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

it('finds the three tenant-owned tables of the reporting slice, all of them scoped', function () {
    $models = modelsByTable();

    foreach (['report_obligations', 'progress_reports', 'progress_report_events'] as $table) {
        expect(tablesWithTenantColumn())->toContain($table)
            ->and($models[$table]['scoped'] ?? false)->toBeTrue();
    }
});

it('finds the tenant-owned tables of the Phase 2 modules, all of them scoped', function () {
    $models = modelsByTable();

    // Every record an MDA creates about its own delivery: the results
    // framework it reports against, the inspections it conducts, the issues it
    // raises, the notices and certificates it issues, the evaluations
    // commissioned over it, and its annual work plan. A missing entry here is
    // not a style problem — it is an unscoped table holding one MDA's field
    // evidence where another MDA can read it.
    foreach ([
        'result_frameworks', 'indicator_reading_events',
        'site_inspections', 'site_inspection_responses', 'site_inspection_events',
        'issues', 'issue_events', 'exception_reports',
        'commencement_notices', 'certificates',
        'evaluations', 'evaluation_team_members', 'evaluation_report_sections',
        'evaluation_criterion_scores', 'evaluation_events', 'recommendations',
        'workplans', 'workplan_activities', 'workplan_events',
    ] as $table) {
        expect(tablesWithTenantColumn())->toContain($table)
            ->and($models[$table]['scoped'] ?? false)->toBeTrue();
    }
});

it('leaves global reference tables unscoped, as cross-MDA aggregation requires', function () {
    $tables = tablesWithTenantColumn();
    $models = modelsByTable();

    // `reporting_periods` belongs on this list by design, not by omission
    // (progress-reporting.md §1.1): the statutory calendar is state-wide, and
    // the compliance league table is only meaningful if every MDA is measured
    // against an identical denominator. A per-tenant calendar would make
    // "which MDA was late" unanswerable.
    // Each of these is global BY DECISION, and each decision is different:
    //  - sectors/funding_sources/lgas/wards/contractors: state-wide reference
    //    data every MDA draws from.
    //  - reporting_periods: the compliance league table is only meaningful if
    //    every MDA is measured against an identical denominator.
    //  - indicator_definitions / inspection_checklist_templates(+_items): the
    //    reusable library and the state's checklists — an MDA INSTANTIATES
    //    these into its own tenant-owned records rather than owning them.
    //  - consolidated_reports(+ sections/entries/events): a state roll-up
    //    SPANS every MDA. A tenant_id here would be wrong twice over.
    //  - report_exports: the artifact register records oversight and tenant
    //    exports alike; provenance lives in generated_for_tenant_id, which is
    //    deliberately not named tenant_id because it is not a scope key.
    //  - feedback(+_responses): a citizen does not know which MDA owns a
    //    project; the record hangs off the project, which carries the tenancy.
    foreach ([
        'sectors', 'funding_sources', 'lgas', 'wards', 'contractors', 'reporting_periods',
        'indicator_definitions', 'inspection_checklist_templates', 'inspection_checklist_template_items',
        'consolidated_reports', 'consolidated_report_sections', 'consolidated_report_entries',
        'consolidation_events', 'report_exports', 'feedback', 'feedback_responses',
    ] as $table) {
        expect($tables)->not->toContain($table)
            ->and($models[$table]['scoped'] ?? false)->toBeFalse();
    }
});

/*
|--------------------------------------------------------------------------
| What the global scope does NOT cover
|--------------------------------------------------------------------------
| Three components used to carry, as a security argument, the claim that a
| Livewire model property "re-hydrates through its own global scope". It does
| not, and a comment is a bad place to keep a belief nobody checks — so the
| belief is checked here instead.
*/

it('records that Livewire restores a model property with global scopes OFF', function () {
    $restoration = new ReflectionMethod(Model::class, 'newQueryForRestoration');
    $body = implode('', array_slice(
        file($restoration->getFileName()) ?: [],
        $restoration->getStartLine() - 1,
        $restoration->getEndLine() - $restoration->getStartLine() + 1,
    ));

    // Livewire's ModelSynth hydrates through this method. If Laravel ever
    // changes it to apply scopes, this test fails — and the components'
    // docblocks, which currently warn that the scope is OFF, become wrong in
    // the safe direction and should be revisited.
    expect($body)->toContain('newQueryWithoutScopes')
        ->and($body)->not->toContain('newQuery()');
});
