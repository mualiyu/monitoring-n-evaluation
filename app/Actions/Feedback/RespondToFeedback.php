<?php

namespace App\Actions\Feedback;

use App\Exceptions\Feedback\FeedbackRuleViolation;
use App\Models\Feedback;
use App\Models\FeedbackResponse;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * An official reply on a feedback thread.
 *
 * This is the half of the module that makes it worth building: a complaints
 * box nobody answers teaches people not to write. A public response shown
 * under the complaint — "inspected on 4 March, the contractor has remobilised"
 * — is the accountability loop closing where everyone can see it.
 *
 * `responded_by_id` and `responded_at` come from the authenticated actor and
 * the clock, never from the form: who answered the public, and when, is not
 * something a payload gets to state.
 *
 * A PUBLIC response may only hang off PUBLISHED feedback. Otherwise an
 * internal note written on a pending complaint would become visible the moment
 * someone published its parent — the leak nobody would think to test for.
 */
class RespondToFeedback
{
    public function __invoke(
        Feedback $feedback,
        User $actor,
        string $body,
        bool $public = true,
    ): FeedbackResponse {
        Gate::forUser($actor)->authorize('respond', $feedback);

        $body = trim($body);

        if ($body === '') {
            throw FeedbackRuleViolation::emptyResponse();
        }

        if ($public && ! $feedback->status->isPublic()) {
            throw FeedbackRuleViolation::responseRequiresPublishedParent();
        }

        $response = new FeedbackResponse;

        $response->fill([
            'feedback_id' => $feedback->id,
            'body' => $body,
            'is_public' => $public,
        ]);

        $response->forceFill([
            'responded_by_id' => $actor->id,
            'responded_at' => now(),
        ])->save();

        return $response;
    }
}
