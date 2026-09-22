<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\User;

/**
 * The taxonomy a person mutes by: what a notification is ABOUT, not which
 * class produced it.
 *
 * Categories are coarse on purpose. A preference screen with one switch per
 * notification class is a screen nobody configures and a contract every new
 * module has to extend; a screen with six switches is one a Permanent
 * Secretary actually sets on their first afternoon. New notification classes
 * join an existing category — they do not add one — unless they are genuinely
 * a new KIND of thing to be told about.
 *
 * MUTABILITY IS NOT UNIFORM. An invitation carries a credential and a
 * deadline; muting it would lock someone out of the platform while telling
 * them nothing. Categories marked non-mutable are delivered regardless of
 * preference, and the preference screen shows them as fixed rather than
 * hiding them — a person is entitled to know what the platform will always
 * send them.
 */
final class NotificationCategories
{
    public const IAM = 'iam';

    public const PROJECTS = 'projects';

    public const REPORTING = 'reporting';

    public const INSPECTIONS = 'inspections';

    public const ISSUES = 'issues';

    public const EVALUATION = 'evaluation';

    public const WORKPLANS = 'workplans';

    /** The channels a person may switch off independently. */
    public const CHANNELS = ['mail', 'database'];

    /**
     * category => [label, description, mutable]
     *
     * @return array<string, array{label: string, description: string, mutable: bool}>
     */
    public static function all(): array
    {
        return [
            self::IAM => [
                'label' => __('Account & access'),
                'description' => __('Invitations, role changes and anything that affects whether you can sign in.'),
                'mutable' => false,
            ],
            self::PROJECTS => [
                'label' => __('Project lifecycle'),
                'description' => __('A project you work on is awarded, suspended, completed or certified.'),
                'mutable' => true,
            ],
            self::REPORTING => [
                'label' => __('Progress reporting'),
                'description' => __('Returns waiting for you, returns sent back, and reminders that a deadline is approaching or missed.'),
                'mutable' => true,
            ],
            self::INSPECTIONS => [
                'label' => __('Site inspections'),
                'description' => __('Visits scheduled for you, and inspection reports awaiting review.'),
                'mutable' => true,
            ],
            self::ISSUES => [
                'label' => __('Issues & exceptions'),
                'description' => __('Challenges raised against a project you monitor, and exceptions that escalate.'),
                'mutable' => true,
            ],
            self::EVALUATION => [
                'label' => __('Evaluation'),
                'description' => __('Evaluations commissioned, scored or approved, and recommendations assigned to you.'),
                'mutable' => true,
            ],
            // A genuinely separate kind of thing to be told about: the annual
            // plan is the MDA's own commitment, not a project's lifecycle or a
            // periodic return, and the people who approve it are not the
            // people who file returns.
            self::WORKPLANS => [
                'label' => __('Work plans'),
                'description' => __('Annual work plans waiting for your approval or sent back, and activities assigned to you or past their date.'),
                'mutable' => true,
            ],
        ];
    }

    public static function exists(string $category): bool
    {
        return array_key_exists($category, self::all());
    }

    public static function label(string $category): string
    {
        return self::all()[$category]['label'] ?? $category;
    }

    public static function isMutable(string $category): bool
    {
        return self::all()[$category]['mutable'] ?? false;
    }

    public static function channelLabel(string $channel): string
    {
        return match ($channel) {
            'mail' => __('Email'),
            'database' => __('In-app'),
            default => ucfirst($channel),
        };
    }

    /**
     * Narrow a notification's channels to the ones this recipient still wants.
     *
     * Three deliberate refusals to guess:
     *  - a notifiable that is not a platform User (an invitation addressed to
     *    a bare email, for instance) has no preferences and gets everything;
     *  - an unknown or non-mutable category is delivered untouched;
     *  - an absent preference means ON, so a category introduced after
     *    somebody set their preferences is never silently muted for them.
     *
     * @param  list<string>  $channels
     * @return list<string>
     */
    public static function filter(string $category, object $notifiable, array $channels): array
    {
        if (! $notifiable instanceof User || ! self::isMutable($category)) {
            return $channels;
        }

        return array_values(array_filter(
            $channels,
            static fn (string $channel): bool => ! $notifiable->hasMutedNotifications($category, $channel),
        ));
    }
}
