<?php

namespace Database\Factories;

use App\Models\Feedback;
use App\Models\FeedbackResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An official reply on a feedback thread — GLOBAL, tenancy inherited from the
 * parent feedback's project.
 *
 * `responded_by_id` and `responded_at` are not fillable on the model (they are
 * stamped by App\Actions\Feedback\RespondToFeedback from the authenticated
 * actor); factories run unguarded, so the fixture can set them.
 *
 * @extends Factory<FeedbackResponse>
 */
class FeedbackResponseFactory extends Factory
{
    protected $model = FeedbackResponse::class;

    public function definition(): array
    {
        return [
            'feedback_id' => Feedback::factory(),
            'body' => 'The site was inspected on 4 March. The contractor has remobilised and work resumed on 11 March.',
            'responded_by_id' => User::factory(),
            'is_public' => true,
            'responded_at' => now()->subHours(6),
        ];
    }

    /** Shown under the comment on the portal — only ever valid on published feedback. */
    public function public(): static
    {
        return $this->state(['is_public' => true]);
    }

    /**
     * A note to colleagues. The leak nobody would think to test for is this one
     * surfacing because its parent was published afterwards.
     */
    public function internal(): static
    {
        return $this->state([
            'is_public' => false,
            'body' => 'Internal: the contractor disputes the measured quantity; hold the payment certificate.',
        ]);
    }

    public function on(Feedback $feedback): static
    {
        return $this->state(['feedback_id' => $feedback->id]);
    }

    public function by(User $user): static
    {
        return $this->state(['responded_by_id' => $user->id]);
    }
}
