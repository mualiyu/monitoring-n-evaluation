<?php

/**
 * Outgoing mail, driven the way production drives it: queued, then picked up
 * by a WORKER that has no request host, no URL defaults and no bound tenant.
 *
 * The rest of the suite runs QUEUE_CONNECTION=sync (phpunit.xml), so every job
 * executes inline inside the request that dispatched it — with the request's
 * host, ResolveTenant's URL::defaults and the tenant already bound. That is
 * precisely the context a real worker does NOT have, and it is how a
 * notification can pass every test and still arrive with a dead link, or not
 * arrive at all. These cases put the job on the database queue, wipe the
 * request-scoped state a fresh worker process would not have, and run the real
 * `queue:work` command.
 *
 * Also asserted here, because it is visible in every one of these messages:
 * the mail is WHITE-LABEL. Laravel's stock templates sign every message
 * "Regards, Laravel", title it "Laravel", footer it "© Laravel" and — while
 * APP_NAME is still "Laravel" — hot-link the Laravel logo from laravel.com
 * into a government inbox.
 */

use App\Actions\Iam\InviteUser;
use App\Enums\Role;
use App\Jobs\Issues\NotifyIssueAssigned;
use App\Jobs\Lifecycle\NotifyCommencementNoticeOverdue;
use App\Models\CommencementNotice;
use App\Models\Contract;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Issues\IssueAssigned;
use App\Notifications\Lifecycle\CommencementNoticeOverdue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);
    URL::defaults(['tenant' => $this->works->slug]);

    $this->admin = memberOf(User::factory()->create(['name' => 'Amina Bello']), $this->works, Role::MdaAdmin);

    config(['platform.instance.name' => 'Riverside State M&E']);
});

/**
 * Hand the queue to a "fresh worker process": nothing the dispatching request
 * bound may still be sitting in the container when the job runs.
 */
function runQueueAsFreshWorker(): void
{
    actingWithoutTenant();
    app()->forgetScopedInstances();

    // ResolveTenant's URL::defaults(['tenant' => …]) lives on the URL
    // generator singleton. A real worker never ran that middleware, so a
    // route() that leans on the default must fail here exactly as it would
    // there.
    (fn () => $this->routeUrl()->defaultParameters = [])->call(app('url'));
    expect(app('url')->getDefaultParameters())->toBe([]);

    test()->artisan('queue:work', [
        'connection' => 'database',
        '--stop-when-empty' => true,
        '--tries' => 1,
        // The worker's own ceiling (default 128 MB) is measured against THIS
        // process, which inside a full suite run is already past it: the
        // worker would exit 12 (memory limit) before taking its first job.
        '--memory' => 4096,
    ])->assertExitCode(0);

    expect(DB::table('failed_jobs')->count())->toBe(0, 'A queued job failed in the worker.');
}

/** @return Collection<int, Email> */
function sentMail(): Collection
{
    /** @var Collection<int, SentMessage> $messages */
    $messages = Mail::mailer('array')->getSymfonyTransport()->messages();

    return $messages->map(function (SentMessage $message): Email {
        $original = $message->getOriginalMessage();
        assert($original instanceof Email);

        return $original;
    });
}

function actionUrlIn(Email $email): string
{
    preg_match('/class="button[^"]*"[^>]*href="([^"]+)"|href="([^"]+)"[^>]*class="button/', (string) $email->getHtmlBody(), $match);

    return html_entity_decode($match[1] ?: ($match[2] ?? ''));
}

/* -------------------------------------------------------------------------- */
/* The invitation: queued, credential-bearing, delivered by a worker */
/* -------------------------------------------------------------------------- */

it('delivers a queued invitation through a real worker, linking to the workspace that invited them', function () {
    config(['queue.default' => 'database']);

    (new InviteUser)($this->admin, 'new.officer@works.test', Role::MeOfficer, $this->works);

    // Nothing is sent at request time — it waits for a worker. This is the
    // whole of the local "emails never arrive" report when no worker runs.
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(sentMail())->toHaveCount(0);

    runQueueAsFreshWorker();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(sentMail())->toHaveCount(1);

    $mail = sentMail()->sole();

    expect($mail->getTo()[0]->getAddress())->toBe('new.officer@works.test')
        ->and(actionUrlIn($mail))->toStartWith('http://works.mne.test/invitations/');
});

it('never writes the plaintext invitation token into the queue store', function () {
    config(['queue.default' => 'database']);

    (new InviteUser)($this->admin, 'new.officer@works.test', Role::MeOfficer, $this->works);

    $payload = (string) DB::table('jobs')->value('payload');

    runQueueAsFreshWorker();

    // The token is only recoverable from the delivered link.
    $token = basename(parse_url(actionUrlIn(sentMail()->sole()), PHP_URL_PATH) ?: '');

    // The jobs table (and failed_jobs, and Redis in production) is readable by
    // anyone with database access. A live, single-use credential must not
    // sit there in the clear for as long as the queue is backed up.
    expect(strlen($token))->toBe(64)
        ->and($payload)->not->toContain($token)
        ->and($payload)->not->toContain('plaintextToken');
});

/* -------------------------------------------------------------------------- */
/* Tenant-aware fan-out jobs: links built with no request host */
/* -------------------------------------------------------------------------- */

it('builds an issue deep link on the workspace host from inside a worker', function () {
    config(['queue.default' => 'database']);

    $owner = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $issue = Issue::factory()->ownedBy($owner)->create(['title' => 'Culvert collapse at chainage 2+150']);

    NotifyIssueAssigned::dispatch($issue->id, $owner->id, $this->admin->id);

    runQueueAsFreshWorker();

    $stored = DatabaseNotification::query()->where('notifiable_id', $owner->id)->sole();

    expect($stored->type)->toBe(IssueAssigned::class)
        ->and($stored->data['tenant_id'])->toBe($this->works->id)
        ->and($stored->data['issue_ulid'])->toBe($issue->ulid);

    expect(actionUrlIn(sentMail()->sole()))->toBe('http://works.mne.test/issues/'.$issue->ulid);
});

it('builds a route()-generated link on the workspace host from inside a worker', function () {
    config(['queue.default' => 'database']);

    $project = Project::factory()->ongoing()->create();
    $contract = Contract::factory()->forProject($project)->create();
    $notice = CommencementNotice::factory()->forContract($contract)->pending()->create();

    NotifyCommencementNoticeOverdue::dispatch($notice->id);

    runQueueAsFreshWorker();

    expect(DatabaseNotification::query()->where('notifiable_id', $this->admin->id)->sole()->type)
        ->toBe(CommencementNoticeOverdue::class)
        ->and(actionUrlIn(sentMail()->sole()))
        ->toBe('http://works.mne.test/projects/'.$project->ulid.'/commencement');
});

/* -------------------------------------------------------------------------- */
/* White-label: no framework branding in anything we send */
/* -------------------------------------------------------------------------- */

it('brands every message with the instance, never with the framework', function () {
    config(['queue.default' => 'database', 'app.name' => 'Laravel']);

    $owner = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $issue = Issue::factory()->ownedBy($owner)->create();

    NotifyIssueAssigned::dispatch($issue->id, $owner->id, $this->admin->id);

    runQueueAsFreshWorker();

    $mail = sentMail()->sole();
    $html = (string) $mail->getHtmlBody();
    $text = (string) $mail->getTextBody();

    // app.name is deliberately left at the framework default above: the
    // templates must not read it at all.
    foreach ([$html, $text] as $body) {
        expect($body)->not->toContain('Laravel')
            ->and($body)->toContain('Riverside State M')
            // The nested-Blade-comment bug class that put a stray `--}}` on
            // the wizards: a comment that leaks renders as text.
            ->and($body)->not->toContain('--}}')
            ->and($body)->not->toContain('{{--');
    }

    expect($html)->not->toContain('laravel.com')
        ->and($html)->toContain('<title>Riverside State M&amp;E</title>')
        ->and($html)->toContain('© '.now()->year.' Riverside State M&amp;E');
});

it('sends the password reset from the apex, branded, with a link on the apex', function () {
    config(['app.name' => 'Laravel']);

    $user = memberOf(User::factory()->create(['email' => 'reset.me@works.test']), $this->works, Role::MeOfficer);

    actingWithoutTenant();

    $this->get(portalUrl('/forgot-password'))->assertOk();
    $this->post(portalUrl('/forgot-password'), ['email' => $user->email])->assertSessionHasNoErrors();

    $mail = sentMail()->sole();

    expect(actionUrlIn($mail))->toStartWith('http://mne.test/reset-password/')
        ->and((string) $mail->getHtmlBody())->not->toContain('Laravel')
        ->and((string) $mail->getTextBody())->not->toContain('Laravel');
});

it('sends the email-change verification from the workspace host, with a signed link that works there', function () {
    config(['app.name' => 'Laravel']);

    $user = memberOf(User::factory()->create(['email' => 'old.address@works.test']), $this->works, Role::MeOfficer);

    $this->actingAs($user)
        ->put(tenantUrl($this->works, '/user/profile-information'), [
            'name' => $user->name,
            'email' => 'new.address@works.test',
        ])
        ->assertSessionHasNoErrors();

    $mail = sentMail()->sole();
    $link = actionUrlIn($mail);

    expect($mail->getTo()[0]->getAddress())->toBe('new.address@works.test')
        ->and($link)->toStartWith('http://works.mne.test/email/verify/')
        ->and((string) $mail->getHtmlBody())->not->toContain('Laravel')
        ->and($user->fresh()->email_verified_at)->toBeNull();

    // The signature covers the host it was minted on, so the link is only
    // proven good by following it there.
    app()->forgetScopedInstances();

    $this->actingAs($user->fresh())->get($link)->assertRedirect();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

/* -------------------------------------------------------------------------- */
/* The From header */
/* -------------------------------------------------------------------------- */

it('defaults the sender name to the instance, not to the framework', function () {
    // Evaluate config/mail.php as a deployment that has NOT set a sender
    // would: MAIL_FROM_* absent, APP_NAME still the framework default.
    $keys = ['MAIL_FROM_NAME', 'MAIL_FROM_ADDRESS', 'APP_NAME', 'PLATFORM_INSTANCE_NAME', 'PLATFORM_DOMAIN'];
    $saved = [];

    foreach ($keys as $key) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    $_ENV['APP_NAME'] = $_SERVER['APP_NAME'] = 'Laravel';
    $_ENV['PLATFORM_INSTANCE_NAME'] = $_SERVER['PLATFORM_INSTANCE_NAME'] = 'Riverside State M&E';
    $_ENV['PLATFORM_DOMAIN'] = $_SERVER['PLATFORM_DOMAIN'] = 'mne.riverside.gov.example';

    try {
        $mail = require base_path('config/mail.php');
    } finally {
        foreach ($saved as $key => [$env, $server, $process]) {
            unset($_ENV[$key], $_SERVER[$key]);
            $env === null ? null : $_ENV[$key] = $env;
            $server === null ? null : $_SERVER[$key] = $server;
            $process === false ? putenv($key) : putenv($key.'='.$process);
        }
    }

    expect($mail['from']['name'])->toBe('Riverside State M&E')
        ->and($mail['from']['address'])->toBe('no-reply@mne.riverside.gov.example');
});
