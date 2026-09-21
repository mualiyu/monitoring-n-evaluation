<?php

namespace Database\Factories;

use App\Enums\FeedbackChannel;
use App\Enums\FeedbackStatus;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Stakeholder feedback — GLOBAL, so this factory never touches tenancy. What it
 * DOES touch is the project, and the default creates one: tenancy is carried by
 * that relation, so a feedback fixture made outside a bound tenant context will
 * fail in ProjectFactory, which is the intended teaching moment. Use
 * ->unattached() for the state-only pile.
 *
 * Every column the Actions own (status, the moderation stamps, the spam flag,
 * the forensic columns) is deliberately not fillable on the model. Factories
 * run unguarded, which is why the states below can set them — application code
 * cannot, and that asymmetry is the point.
 *
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    protected $model = Feedback::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'subject' => fake()->randomElement([
                'Work has stopped at the site',
                'The borehole has not been commissioned',
                'Drainage completed and working',
                'No contractor on site since the rains',
                'Classroom block roofed but no windows',
            ]),
            'body' => fake()->paragraph(4),
            'submitter_name' => fake()->name(),
            'submitter_email' => fake()->safeEmail(),
            'submitter_phone' => null,
            'channel' => FeedbackChannel::Portal,
            'status' => FeedbackStatus::Pending,
            'moderated_by_id' => null,
            'moderated_at' => null,
            'moderation_reason' => null,
            'flagged_as_spam' => false,
            'spam_reason' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'Mozilla/5.0 (Linux; Android 10)',
        ];
    }

    /** Straight off the public form and nobody has looked at it yet. */
    public function pending(): static
    {
        return $this->state([
            'status' => FeedbackStatus::Pending,
            'moderated_by_id' => null,
            'moderated_at' => null,
            'moderation_reason' => null,
        ]);
    }

    /** A moderator opened it to the public — the only state the portal renders. */
    public function published(?User $moderator = null): static
    {
        return $this->state(fn (): array => [
            'status' => FeedbackStatus::Published,
            'moderated_by_id' => $moderator->id ?? User::factory(),
            'moderated_at' => now()->subDay(),
            'moderation_reason' => null,
        ]);
    }

    public function rejected(?User $moderator = null): static
    {
        return $this->state(fn (): array => [
            'status' => FeedbackStatus::Rejected,
            'moderated_by_id' => $moderator->id ?? User::factory(),
            'moderated_at' => now()->subDay(),
            'moderation_reason' => 'Names a private individual and repeats an unverified allegation.',
        ]);
    }

    public function spam(?User $moderator = null): static
    {
        return $this->state(fn (): array => [
            'status' => FeedbackStatus::Spam,
            'moderated_by_id' => $moderator->id ?? User::factory(),
            'moderated_at' => now()->subDay(),
            'moderation_reason' => 'Advertising.',
            'flagged_as_spam' => true,
            'spam_reason' => 'Contains 4 links.',
        ]);
    }

    /** Marked by the heuristic, still waiting on a human — the queue's top case. */
    public function flagged(): static
    {
        return $this->state([
            'status' => FeedbackStatus::Pending,
            'flagged_as_spam' => true,
            'spam_reason' => 'Shorter than 20 characters.',
        ]);
    }

    /**
     * No project. Invisible to every MDA and visible only to the state
     * secretariat — the fixture for that whole branch of the module.
     */
    public function unattached(): static
    {
        return $this->state(['project_id' => null]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id]);
    }

    /** Someone who gave no name — legitimate, and the display-label case. */
    public function anonymous(): static
    {
        return $this->state([
            'submitter_name' => null,
            'submitter_email' => null,
            'submitter_phone' => null,
        ]);
    }

    public function viaChannel(FeedbackChannel $channel): static
    {
        return $this->state(['channel' => $channel]);
    }
}
