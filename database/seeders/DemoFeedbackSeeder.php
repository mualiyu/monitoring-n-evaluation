<?php

namespace Database\Seeders;

use App\Enums\FeedbackChannel;
use App\Enums\FeedbackStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\Feedback;
use App\Models\FeedbackResponse;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * The public portal, with something on it.
 *
 * TWO JOBS, and the first one is not optional: this seeder PUBLISHES a handful
 * of each demo MDA's projects. Without that the transparency portal is an empty
 * state on every screen, the map has no pins, and a feedback register about
 * projects nobody can see demonstrates nothing. Publishing is the precondition
 * for the rest of the module existing.
 *
 * Then the register itself, deliberately spread across every moderation state:
 *  - a published comment WITH an official public response — the accountability
 *    loop closing where everyone can see it, which is the whole argument for
 *    building this;
 *  - a pending comment, so the queue is not empty on a demo;
 *  - a refused one with a stated reason, and one marked as spam, so the two
 *    decisions that need justifying are visible on the record;
 *  - a published comment carrying an INTERNAL note as well as a public reply,
 *    which is the fixture the "internal notes never leak" test needs;
 *  - an UNATTACHED comment, which appears only on the state queue.
 *
 * Fixtures, not Actions — like DemoIssueSeeder. Driving the real chain would
 * fire queued notifications during `migrate:fresh --seed`, and the guards those
 * Actions carry are proved by tests, not by a demo dataset.
 *
 * `feedback` is a GLOBAL table: nothing here is tenant-owned, and the rows are
 * written outside any tenant context. Tenancy reaches them only through the
 * project each one points at.
 *
 * Depends on: DemoTenantSeeder, DemoProjectSeeder.
 */
class DemoFeedbackSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $current = app(CurrentTenant::class);
        $current->forget();

        $published = [];
        $staff = [];

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $admin = $this->firstWithRole($tenant, Role::MdaAdmin);

            if (! $admin instanceof User) {
                continue;
            }

            $staff[$tenant->id] = $admin;

            foreach ($this->publishFor($tenant, $admin) as $project) {
                $published[] = $project;
            }
        }

        if ($published === []) {
            return;
        }

        $this->register($published, $staff);
    }

    /**
     * Open this MDA's delivered work to the public.
     *
     * Only statuses PublishProjectToPortal would accept, and only a few of
     * them: a demo where every project is public teaches nobody what the
     * publishing queue is for.
     *
     * @return list<Project>
     */
    private function publishFor(Tenant $tenant, User $admin): array
    {
        return app(CurrentTenant::class)->runAs($tenant, function () use ($admin): array {
            $candidates = Project::query()
                ->whereIn('status', [
                    ProjectStatus::InProgress,
                    ProjectStatus::Completed,
                    ProjectStatus::Certified,
                    ProjectStatus::Suspended,
                ])
                ->whereNull('published_at')
                ->orderBy('id')
                ->limit(4)
                ->get();

            $opened = [];

            foreach ($candidates as $project) {
                // published_at / published_by_id are deliberately not fillable:
                // publishing is an explicit act, never something a payload can
                // turn on. A seeder states where the records ARE.
                $project->forceFill([
                    'published_at' => CarbonImmutable::now()->subDays(14 - count($opened)),
                    'published_by_id' => $admin->id,
                ])->save();

                $opened[] = $project;
            }

            return $opened;
        });
    }

    /**
     * @param  list<Project>  $published
     * @param  array<int, User>  $staff
     */
    private function register(array $published, array $staff): void
    {
        $first = $published[0];
        $second = $published[1] ?? $first;
        $third = $published[2] ?? $first;

        $moderator = $staff[$first->tenant_id] ?? null;

        // 1. Published, answered — the loop closing in public.
        $answered = $this->feedback($first, [
            'subject' => 'Work stopped at the site in March',
            'body' => 'There has been nobody on this site since the second week of March. The excavation is open '
                .'and unfenced, and children pass it on the way to school every morning.',
            'submitter_name' => 'Hauwa I.',
            'status' => FeedbackStatus::Published,
            'moderated_by_id' => $moderator?->id,
            'daysAgo' => 21,
        ]);

        if ($moderator instanceof User) {
            $this->response($answered, $moderator, [
                'body' => 'Thank you. The site was inspected on 4 March. The contractor has remobilised, the '
                    .'excavation was fenced on 8 March and works resumed on 11 March.',
                'is_public' => true,
                'daysAgo' => 17,
            ]);
        }

        // 2. Waiting on a moderator — so the queue is not empty on a demo.
        $this->feedback($second, [
            'subject' => 'The borehole has not been commissioned',
            'body' => 'The borehole was drilled and the tank installed before the rains, but it has never been '
                .'switched on and the community still draws from the stream.',
            'submitter_name' => null,
            'status' => FeedbackStatus::Pending,
            'daysAgo' => 4,
        ]);

        // 3. Refused, with the reason on the record. Refusing to publish a
        //    citizen's complaint is a decision, and it is recorded as one.
        $this->feedback($second, [
            'subject' => 'Allegation about the site supervisor',
            'body' => 'The supervisor on this site is reported to be collecting money from suppliers before '
                .'releasing their deliveries.',
            'submitter_name' => 'Anonymous',
            'status' => FeedbackStatus::Rejected,
            'moderated_by_id' => $staff[$second->tenant_id]->id ?? null,
            'moderation_reason' => 'Names an individual and makes an allegation the monitoring team could not '
                .'substantiate on site. Referred to the anti-corruption desk instead.',
            'daysAgo' => 11,
        ]);

        // 4. Spam, marked by the heuristic and confirmed by a human. The filter
        //    marks; it never decides.
        $this->feedback($third, [
            'subject' => 'CHEAP BUILDING MATERIALS BEST PRICE',
            'body' => 'Buy cement and iron rods at wholesale price visit https://example.test and '
                .'https://example.test/offers and https://example.test/deals today.',
            'submitter_name' => 'Sales Desk',
            'status' => FeedbackStatus::Spam,
            'moderated_by_id' => $staff[$third->tenant_id]->id ?? null,
            'moderation_reason' => 'Advertising.',
            'flagged_as_spam' => true,
            'spam_reason' => 'Contains 3 links.',
            'daysAgo' => 6,
        ]);

        // 5. Published, with a public reply AND an internal note beneath it.
        //    The internal note is the leak nobody would think to test for.
        $noted = $this->feedback($third, [
            'subject' => 'Drainage completed and working',
            'body' => 'The drainage along the market road was finished before the rains and the road did not '
                .'flood this year for the first time in four years.',
            'submitter_name' => 'Musa A.',
            'status' => FeedbackStatus::Published,
            'moderated_by_id' => $staff[$third->tenant_id]->id ?? null,
            'daysAgo' => 30,
            'channel' => FeedbackChannel::TownHall,
        ]);

        $noteAuthor = $staff[$third->tenant_id] ?? $moderator;

        if ($noteAuthor instanceof User) {
            $this->response($noted, $noteAuthor, [
                'body' => 'Thank you for telling us. The section was certified on 12 February.',
                'is_public' => true,
                'daysAgo' => 28,
            ]);

            $this->response($noted, $noteAuthor, [
                'body' => 'Internal: the contractor disputes the measured quantity on the last 40m; hold the '
                    .'retention certificate until the joint re-measurement.',
                'is_public' => false,
                'daysAgo' => 27,
            ]);
        }

        // 6. No project attached. Invisible to every MDA, and the reason the
        //    state queue exists at all.
        $this->feedback(null, [
            'subject' => 'The road by the market',
            'body' => 'Somebody started grading the road behind the main market last year and then left. I do '
                .'not know which ministry it belongs to, but nobody has come back.',
            'submitter_name' => null,
            'status' => FeedbackStatus::Pending,
            'daysAgo' => 2,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function feedback(?Project $project, array $attributes): Feedback
    {
        $createdAt = CarbonImmutable::now()->subDays((int) ($attributes['daysAgo'] ?? 7));

        $feedback = new Feedback;

        $feedback->fill([
            'project_id' => $project?->id,
            'subject' => (string) $attributes['subject'],
            'body' => (string) $attributes['body'],
            'submitter_name' => $attributes['submitter_name'] ?? null,
            'submitter_email' => $attributes['submitter_email'] ?? null,
            'channel' => $attributes['channel'] ?? FeedbackChannel::Portal,
        ]);

        /** @var FeedbackStatus $status */
        $status = $attributes['status'];

        // Guarded by omission on the model: the moderation columns belong to
        // ModerateFeedback and the forensic columns to SubmitFeedback, so a
        // fixture has to say so explicitly.
        $feedback->forceFill([
            'status' => $status,
            'moderated_by_id' => $attributes['moderated_by_id'] ?? null,
            'moderated_at' => $status->isModerated() ? $createdAt->addDay() : null,
            'moderation_reason' => $attributes['moderation_reason'] ?? null,
            'flagged_as_spam' => (bool) ($attributes['flagged_as_spam'] ?? false),
            'spam_reason' => $attributes['spam_reason'] ?? null,
            'ip_address' => '198.51.100.'.random_int(2, 250),
            'user_agent' => 'Mozilla/5.0 (Linux; Android 10)',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $feedback;
    }

    /** @param  array<string, mixed>  $attributes */
    private function response(Feedback $feedback, User $author, array $attributes): FeedbackResponse
    {
        $respondedAt = CarbonImmutable::now()->subDays((int) ($attributes['daysAgo'] ?? 3));

        $response = new FeedbackResponse;

        $response->fill([
            'feedback_id' => $feedback->id,
            'body' => (string) $attributes['body'],
            'is_public' => (bool) $attributes['is_public'],
        ]);

        $response->forceFill([
            'responded_by_id' => $author->id,
            'responded_at' => $respondedAt,
            'created_at' => $respondedAt,
            'updated_at' => $respondedAt,
        ])->save();

        return $response;
    }

    private function firstWithRole(Tenant $tenant, Role $role): ?User
    {
        // spatie resolves the `role` scope against the CURRENT permission team,
        // which runAs() binds — so this can only ever find users of this MDA.
        return app(CurrentTenant::class)->runAs(
            $tenant,
            fn (): ?User => User::query()->role($role->value)->orderBy('id')->first(),
        );
    }
}
