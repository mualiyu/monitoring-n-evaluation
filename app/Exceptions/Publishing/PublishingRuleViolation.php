<?php

namespace App\Exceptions\Publishing;

use App\Enums\ProjectStatus;
use DomainException;

/**
 * A domain precondition refused a publishing decision.
 *
 * Kept beside the publishing module rather than folded into
 * App\Exceptions\Projects\ProjectRuleViolation: publishing is a transparency
 * decision, not a project lifecycle rule, and the two answer to different
 * people — an MDA admin owns the lifecycle, the secretariat owns what the
 * public sees.
 */
class PublishingRuleViolation extends DomainException
{
    public static function notPublished(): self
    {
        return new self('This project is not published, so there is nothing to withdraw.');
    }

    public static function reasonRequired(): self
    {
        return new self(
            'Withdrawing a project from the public portal requires a stated reason — the public saw it, '
            .'and the record has to say why they no longer do.'
        );
    }

    /**
     * A draft project has no award, no contractor and no verified progress:
     * publishing one puts a figure on a public website that nobody has yet
     * attested to. Cancelled projects are equally unpublishable — a project
     * the state abandoned should not appear on a transparency portal as
     * though it were being delivered.
     */
    public static function notPublishable(ProjectStatus $status): self
    {
        return new self(
            "A [{$status->value}] project cannot be published: the portal serves projects the state has "
            .'actually committed to and can be held to.'
        );
    }
}
