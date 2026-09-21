<?php

/**
 * The commissioning form and the evaluation document vault.
 *
 * THE POINT OF THE FIRST HALF is the cases that read the RENDERED HTML and
 * feed the value they find straight back through validation. Livewire::test()
 * sets properties directly and never renders an <option> or crosses HTTP, and
 * three shipped defects in this project passed a green suite that way — one of
 * them being every record-picking <select> submitting the row's NAME where an
 * id was expected, which made the project wizard unusable while the tests
 * stayed green. The lead-evaluator picker here is exactly that shape: an
 * id-keyed map from ->pluck('name', 'id').
 *
 * THE SECOND HALF is the vault. Terms of reference, inception reports,
 * validation-workshop minutes and the signed report are government records:
 * they land on a private disk under a generated name, the mime type is
 * sniffed rather than trusted, and the vault closes when the findings freeze.
 *
 * Note the fixture. UploadedFile::fake()->create() writes a file of NULL
 * BYTES and declares whatever mime it is told to — the name is a fiction and
 * the contents are not a PDF, which is what a truncated or disguised upload
 * looks like. Anything that needs to get past the vault's sniffed allow-list
 * has to hand it real bytes (torPdf() below), and anything that must be
 * refused is built with ->create().
 */

use App\Actions\Documents\AttachDocument;
use App\Enums\EvaluationStatus;
use App\Enums\EvaluationType;
use App\Enums\Role;
use App\Livewire\Shared\DocumentPanel;
use App\Livewire\Tenant\Evaluation\EvaluationCreate;
use App\Models\Evaluation;
use App\Models\EvaluationTeamMember;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Notification::fake();
    Storage::fake('documents');
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingOnTenant($this->works);

    $this->director = memberOf(User::factory()->create(['name' => 'Bolanle Adewale']), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(['name' => 'Ifeoma Nnadi']), $this->works, Role::MeOfficer);
    $this->monitor = memberOf(User::factory()->create(['name' => 'Segun Fashola']), $this->works, Role::FieldMonitor);

    actingOnTenant($this->works);

    $this->project = Project::factory()->ongoing()->create([
        'title' => 'Township Road Rehabilitation',
        'reference' => 'WKS/2026/001',
    ]);
});

/**
 * Every [value => label] pair of one named <select> in a chunk of markup,
 * placeholder dropped. Scoped to the named select so an assertion cannot pass
 * on a coincidental match elsewhere on a page full of dropdowns.
 *
 * @return array<string, string>
 */
function commissionSelectOptions(string $html, string $name): array
{
    preg_match('/<select\b[^>]*\bname="'.preg_quote($name, '/').'"[^>]*>(.*?)<\/select>/s', $html, $block);

    preg_match_all('/<option value="([^"]*)"[^>]*>\s*(.*?)\s*<\/option>/s', $block[1] ?? '', $matches, PREG_SET_ORDER);

    return collect($matches)
        ->mapWithKeys(fn (array $m): array => [
            html_entity_decode($m[1], ENT_QUOTES) => html_entity_decode(trim($m[2]), ENT_QUOTES),
        ])
        ->reject(fn (string $label, string $value): bool => $value === '')
        ->all();
}

/**
 * What is actually in an evaluation's vault, re-read from the database. The
 * model instance a test holds carries whatever media relation it loaded
 * first, which is not the same question.
 *
 * @return Collection<int, Media>
 */
function vaultOf(Evaluation $evaluation): Collection
{
    return Evaluation::query()
        ->whereKey($evaluation->getKey())
        ->firstOrFail()
        ->getMedia('evaluation_documents');
}

/**
 * A fake upload with REAL bytes, so it survives mime sniffing. The four-line
 * body below is a structurally valid PDF as far as finfo is concerned.
 */
function torPdf(string $name = 'tor.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
    );
}

/* -------------------------------------------------------------------------- */
/* The rendered form, fed back through validation */
/* -------------------------------------------------------------------------- */

it('renders the lead-evaluator picker keyed by id, and commissions with exactly what it submits', function () {
    $this->actingAs($this->officer);

    $html = (string) $this->get(tenantUrl($this->works, '/evaluations/create'))->assertOk()->getContent();

    $options = commissionSelectOptions($html, 'leadUserId');

    // An id-keyed map: ->pluck('name', 'id') has INTEGER keys, and those keys
    // are still keys. Rendering the name as the option value is the exact
    // defect that made the project wizard unusable — here it would send a
    // person's name where the roster expects a user id.
    expect($options)->toBe([
        (string) $this->director->id => 'Bolanle Adewale',
        (string) $this->officer->id => 'Ifeoma Nnadi',
        (string) $this->monitor->id => 'Segun Fashola',
    ]);

    // Pull the submitted value out of the real markup rather than assuming it.
    $submitted = (string) array_search('Bolanle Adewale', $options, true);

    expect($submitted)->toBe((string) $this->director->id);

    Livewire::actingAs($this->officer)
        ->test(EvaluationCreate::class)
        ->set('scope', 'project')
        ->set('projectUlid', $this->project->ulid)
        ->set('type', EvaluationType::MidTerm->value)
        ->set('title', 'Mid-term evaluation of the township road programme')
        ->set('purpose', 'Establish whether the intervention is on course to deliver its outcome targets.')
        ->set('sponsor', 'State M&E Secretariat')
        ->set('leadUserId', $submitted)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('failure', null);

    $evaluation = Evaluation::query()->where('title', 'Mid-term evaluation of the township road programme')->firstOrFail();

    expect($evaluation->status)->toBe(EvaluationStatus::Planned)
        ->and($evaluation->project_id)->toBe($this->project->id)
        // The value the markup submitted resolved to the PERSON it named.
        ->and(EvaluationTeamMember::query()
            ->where('evaluation_id', $evaluation->id)
            ->where('role', EvaluationTeamMember::ROLE_LEAD)
            ->value('user_id'))->toBe($this->director->id);
});

it('renders the project picker keyed by ULID, never by primary key', function () {
    $second = Project::factory()->ongoing()->create(['title' => 'Storm Drainage Upgrade']);

    $this->actingAs($this->officer);

    $html = (string) $this->get(tenantUrl($this->works, '/evaluations/create'))->assertOk()->getContent();

    $options = commissionSelectOptions($html, 'projectUlid');

    // An auto-increment id in a bookmarked URL both enumerates another
    // entity's volumes and invites a guess at a row that is not yours.
    expect($options)->toBe([
        $second->ulid => 'Storm Drainage Upgrade',
        $this->project->ulid => 'Township Road Rehabilitation',
    ]);

    $submitted = (string) array_search('Township Road Rehabilitation', $options, true);

    Livewire::actingAs($this->officer)
        ->test(EvaluationCreate::class)
        ->set('projectUlid', $submitted)
        ->set('title', 'Mid-term evaluation of the township road programme')
        ->set('purpose', 'Establish whether the intervention is on course to deliver its outcome targets.')
        ->set('sponsor', 'State M&E Secretariat')
        ->set('leadUserId', (string) $this->director->id)
        ->call('save')
        ->assertHasNoErrors('projectUlid');

    expect(Evaluation::query()->where('project_id', $this->project->id)->exists())->toBeTrue();
});

it('renders the type and scope pickers as the values the Action stores', function () {
    $this->actingAs($this->officer);

    $html = (string) $this->get(tenantUrl($this->works, '/evaluations/create'))->assertOk()->getContent();

    // The enum VALUES, not the labels. An evaluation relabelled after its
    // findings became inconvenient is the failure the immutable type guards
    // against, and it starts with the form storing the right token.
    expect(commissionSelectOptions($html, 'type'))->toBe(EvaluationType::options())
        ->and(array_keys(commissionSelectOptions($html, 'scope')))
        ->toBe(Evaluation::SCOPES);
});

it('still rejects a lead evaluator named instead of identified', function () {
    // The guard must keep biting: the fix for a wrong option value is the
    // option value, never a loosened rule.
    Livewire::actingAs($this->officer)
        ->test(EvaluationCreate::class)
        ->set('projectUlid', $this->project->ulid)
        ->set('title', 'Mid-term evaluation of the township road programme')
        ->set('purpose', 'Establish whether the intervention is on course to deliver its outcome targets.')
        ->set('sponsor', 'State M&E Secretariat')
        ->set('leadUserId', 'Bolanle Adewale')
        ->call('save')
        ->assertHasErrors('leadUserId');

    expect(Evaluation::query()->count())->toBe(0);
});

/* -------------------------------------------------------------------------- */
/* Commission validation */
/* -------------------------------------------------------------------------- */

it('refuses a commission with no lead at all', function () {
    // The lead signs for the findings and, for that reason, cannot approve
    // them — a commission with no lead would make that guard vacuous.
    Livewire::actingAs($this->officer)
        ->test(EvaluationCreate::class)
        ->set('projectUlid', $this->project->ulid)
        ->set('title', 'Mid-term evaluation of the township road programme')
        ->set('purpose', 'Establish whether the intervention is on course to deliver its outcome targets.')
        ->set('sponsor', 'State M&E Secretariat')
        ->call('save')
        ->assertHasErrors(['leadUserId', 'leadExternalName']);
});

it('accepts a contracted external as the lead, with no account on the platform', function () {
    // Most real evaluation teams are firms and academics with no account
    // here; a roster that could only name account holders would be a roster
    // of the wrong people.
    Livewire::actingAs($this->officer)
        ->test(EvaluationCreate::class)
        ->set('projectUlid', $this->project->ulid)
        ->set('title', 'Mid-term evaluation of the township road programme')
        ->set('purpose', 'Establish whether the intervention is on course to deliver its outcome targets.')
        ->set('sponsor', 'State M&E Secretariat')
        ->set('leadExternalName', 'Dr. A. Balogun')
        ->set('leadOrganisation', 'Independent')
        ->call('save')
        ->assertHasNoErrors();

    $lead = EvaluationTeamMember::query()->where('role', EvaluationTeamMember::ROLE_LEAD)->firstOrFail();

    expect($lead->user_id)->toBeNull()
        ->and($lead->displayName())->toBe('Dr. A. Balogun')
        ->and($lead->affiliation())->toBe('Independent');
});

it('requires a subject in exactly one direction', function () {
    // A project evaluation names a project; anything else names what it
    // covers. A commission with no subject cannot be reported on and the
    // report template's title page has nothing to print.
    Livewire::actingAs($this->officer)
        ->test(EvaluationCreate::class)
        ->set('scope', 'project')
        ->set('title', 'An evaluation of something unspecified')
        ->set('purpose', 'Establish whether the intervention is on course to deliver its outcome targets.')
        ->set('sponsor', 'State M&E Secretariat')
        ->set('leadUserId', (string) $this->director->id)
        ->call('save')
        ->assertHasErrors('projectUlid');

    Livewire::actingAs($this->officer)
        ->test(EvaluationCreate::class)
        ->set('scope', 'programme')
        ->set('title', 'An evaluation of something unspecified')
        ->set('purpose', 'Establish whether the intervention is on course to deliver its outcome targets.')
        ->set('sponsor', 'State M&E Secretariat')
        ->set('leadUserId', (string) $this->director->id)
        ->call('save')
        ->assertHasErrors('subjectName');
});

it('refuses a budget that is not a plain amount', function (string $budget) {
    // `numeric` alone accepts '5.' and '1e5', which the Money cast then
    // rejects with a 500.
    Livewire::actingAs($this->officer)
        ->test(EvaluationCreate::class)
        ->set('projectUlid', $this->project->ulid)
        ->set('title', 'Mid-term evaluation of the township road programme')
        ->set('purpose', 'Establish whether the intervention is on course to deliver its outcome targets.')
        ->set('sponsor', 'State M&E Secretariat')
        ->set('leadUserId', (string) $this->director->id)
        ->set('budget', $budget)
        ->call('save')
        ->assertHasErrors('budget');
})->with([
    'a bare decimal point' => ['4500000.'],
    'scientific notation' => ['4.5e6'],
    'a negative sum' => ['-1'],
]);

it('stores a well-formed budget as a decimal, never as a float', function () {
    Livewire::actingAs($this->officer)
        ->test(EvaluationCreate::class)
        ->set('projectUlid', $this->project->ulid)
        ->set('title', 'Mid-term evaluation of the township road programme')
        ->set('purpose', 'Establish whether the intervention is on course to deliver its outcome targets.')
        ->set('sponsor', 'State M&E Secretariat')
        ->set('leadUserId', (string) $this->director->id)
        ->set('budget', '4500000.50')
        ->call('save')
        ->assertHasNoErrors();

    expect(Evaluation::query()->firstOrFail()->budget?->toDecimalString())->toBe('4500000.50');
});

it('refuses an evaluation that ends before it starts', function () {
    Livewire::actingAs($this->officer)
        ->test(EvaluationCreate::class)
        ->set('projectUlid', $this->project->ulid)
        ->set('title', 'Mid-term evaluation of the township road programme')
        ->set('purpose', 'Establish whether the intervention is on course to deliver its outcome targets.')
        ->set('sponsor', 'State M&E Secretariat')
        ->set('leadUserId', (string) $this->director->id)
        ->set('startsOn', '2026-06-01')
        ->set('endsOn', '2026-05-01')
        ->call('save')
        ->assertHasErrors('endsOn');
});

it('refuses a purpose nobody can scope a commission from', function () {
    Livewire::actingAs($this->officer)
        ->test(EvaluationCreate::class)
        ->set('projectUlid', $this->project->ulid)
        ->set('title', 'Mid-term evaluation of the township road programme')
        ->set('purpose', 'Because.')
        ->set('sponsor', 'State M&E Secretariat')
        ->set('leadUserId', (string) $this->director->id)
        ->call('save')
        ->assertHasErrors('purpose');
});

it('matches no project when the pre-selected ULID belongs to another entity', function () {
    $foreign = $this->current->runAs(
        $this->health,
        fn (): Project => Project::factory()->ongoing()->create(['title' => 'Model Primary Health Centre']),
    );

    actingOnTenant($this->works);

    // Resolved through the model, so the TenantScope confines it: a ULID from
    // another workspace is "not found" rather than a row filtered out
    // somewhere later. The commission then has no subject, and is refused.
    Livewire::actingAs($this->officer)
        ->test(EvaluationCreate::class)
        ->set('projectUlid', $foreign->ulid)
        ->set('title', 'Mid-term evaluation of somebody else’s clinic')
        ->set('purpose', 'Establish whether the intervention is on course to deliver its outcome targets.')
        ->set('sponsor', 'State M&E Secretariat')
        ->set('leadUserId', (string) $this->director->id)
        ->call('save');

    expect(Evaluation::query()->count())->toBe(0);
});

/* -------------------------------------------------------------------------- */
/* The evaluation vault */
/* -------------------------------------------------------------------------- */

describe('the evaluation document vault', function () {
    beforeEach(function () {
        actingOnTenant($this->works);

        $this->evaluation = Evaluation::factory()->forProject($this->project)->inProgress()->create();
    });

    it('files the terms of reference on the private disk under a generated name', function () {
        Livewire::actingAs($this->officer)
            ->test(DocumentPanel::class, [
                'model' => $this->evaluation,
                'collection' => 'evaluation_documents',
            ])
            ->set('upload', torPdf())
            ->set('title', 'Terms of reference')
            ->call('save')
            ->assertHasNoErrors();

        // Re-queried, never read off the instance the test has been holding:
        // the panel attached the file to its OWN hydrated copy, and a stale
        // media relation here would report an empty vault.
        $media = vaultOf($this->evaluation)->firstOrFail();

        expect($media->disk)->toBe('documents')
            // A user-controlled filename on disk is a traversal and a
            // content-sniffing problem wearing a label.
            ->and($media->file_name)->not->toContain('tor')
            ->and($media->file_name)->toEndWith('.pdf')
            ->and($media->name)->toBe('Terms of reference')
            ->and($media->getCustomProperty('original_file_name'))->toBe('tor.pdf')
            ->and($media->getCustomProperty('uploaded_by_id'))->toBe($this->officer->id);

        Storage::disk('documents')->assertExists($media->id.'/'.$media->file_name);
    });

    it('refuses a file whose bytes are not what its name claims', function () {
        // UploadedFile::fake()->create() writes a file of null bytes and
        // DECLARES it a PDF. The refusal comes from the collection's own
        // allow-list (HasDocuments::registerMediaCollections →
        // acceptsMimeTypes), which reads the file on disk rather than
        // trusting the name — a truncated or disguised upload looks exactly
        // like this.
        expect(fn () => app(AttachDocument::class)(
            $this->evaluation,
            'evaluation_documents',
            UploadedFile::fake()->create('tor.pdf', 64, 'application/pdf'),
            $this->officer,
        ))->toThrow(FileUnacceptableForCollection::class);

        expect(vaultOf($this->evaluation))->toHaveCount(0);
    });

    it('keeps a disguised file out of the vault through the panel as well', function () {
        $panel = Livewire::actingAs($this->officer)->test(DocumentPanel::class, [
            'model' => $this->evaluation,
            'collection' => 'evaluation_documents',
        ]);

        // Asserted on the CLASS of the refusal because that is what happens
        // today: DocumentPanel::save() catches ValidationException only, so
        // medialibrary's sniffed-type guard surfaces as an unhandled
        // exception rather than as an inline message. The invariant that must
        // never change is the second assertion — nothing is stored. (Wrapping
        // the refusal into a validation message belongs to the shared
        // documents module, and is flagged to the integrator rather than
        // changed from here.)
        expect(fn () => $panel
            ->set('upload', UploadedFile::fake()->create('tor.pdf', 64, 'application/pdf'))
            ->call('save'))->toThrow(FileUnacceptableForCollection::class);

        expect(vaultOf($this->evaluation))->toHaveCount(0);
    });

    it('refuses a file over the collection ceiling', function () {
        // The ceiling is lowered for the test rather than building a 20MB
        // string: the code path under test is the `max:` rule derived from
        // config, and proving it fires at 1KB proves it fires at 20MB.
        config(['documents.collections.evaluation_documents.max_kb' => 1]);

        Livewire::actingAs($this->officer)
            ->test(DocumentPanel::class, [
                'model' => $this->evaluation,
                'collection' => 'evaluation_documents',
            ])
            ->set('upload', UploadedFile::fake()->createWithContent(
                'tor.pdf',
                "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n".str_repeat(' ', 4096),
            ))
            ->call('save')
            ->assertHasErrors('upload');
    });

    it('closes the vault to new uploads once the findings have frozen', function () {
        // The detail screen passes :readonly="! $canEdit", and the panel has
        // to refuse on the METHOD as well — a Livewire endpoint is a public
        // endpoint, and hiding the control is a courtesy.
        Livewire::actingAs($this->officer)
            ->test(DocumentPanel::class, [
                'model' => $this->evaluation,
                'collection' => 'evaluation_documents',
                'readonly' => true,
            ])
            ->set('upload', torPdf())
            ->call('save')
            ->assertForbidden();

        expect(vaultOf($this->evaluation))->toHaveCount(0);
    });

    it('refuses the vault of an evaluation belonging to another entity', function () {
        $foreign = $this->current->runAs($this->health, fn (): Evaluation => Evaluation::factory()->create());

        actingOnTenant($this->works);

        Livewire::actingAs($this->officer)
            ->test(DocumentPanel::class, [
                'model' => $foreign,
                'collection' => 'evaluation_documents',
            ])
            ->assertForbidden();
    });
});
