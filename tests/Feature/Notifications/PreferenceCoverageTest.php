<?php

/**
 * "Notifications are queued and preference-aware" (rules/architecture.md) is a
 * property of EVERY notification the platform sends, not of the ones that
 * happened to be written after the preferences screen existed.
 *
 * The screen promises, for example, that muting "Project lifecycle" silences
 * news of a project being "awarded, suspended, completed or certified". A
 * certificate notification that never consulted the preference would break
 * that promise silently: the switch shows off, the mail keeps coming, and the
 * person concludes the platform ignores them.
 *
 * Checked structurally over the folder, so a notification class added next
 * month is covered on the day it lands rather than when somebody notices.
 */

use App\Actions\Settings\SaveNotificationPreferences;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Concerns\NotificationCategories;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Lifecycle\CommencementNoticeIssued;
use App\Notifications\Lifecycle\CommencementNoticeOverdue;
use App\Notifications\Lifecycle\CompletionCertificateIssued;
use App\Notifications\Workplans\WorkplanActivityAssigned;
use App\Notifications\Workplans\WorkplanActivityOverdue;
use App\Notifications\Workplans\WorkplanChainUpdated;
use Illuminate\Notifications\Notification;
use Symfony\Component\Finder\Finder;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['slug' => 'works']);
    actingOnTenant($this->works);

    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
});

/**
 * Every concrete notification class in app/Notifications.
 *
 * @return list<class-string<Notification>>
 */
function platformNotificationClasses(): array
{
    $classes = [];

    // A path, not app_path(): the dataset is built before the application
    // boots.
    foreach (Finder::create()->files()->in(dirname(__DIR__, 3).'/app/Notifications')->name('*.php') as $file) {
        $class = 'App\\Notifications\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class)) {
            continue; // traits and concerns
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Notification::class)) {
            continue;
        }

        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

/** A notification instance for via(), which reads nothing from the constructor. */
function bareNotification(string $class): Notification
{
    /** @var Notification $notification */
    $notification = (new ReflectionClass($class))->newInstanceWithoutConstructor();

    return $notification;
}

it('finds the platform’s notifications to check', function () {
    // Guard against the structural test below passing over an empty list.
    expect(count(platformNotificationClasses()))->toBeGreaterThanOrEqual(25);
});

it('routes every notification through the preference filter, under a real category', function (string $class) {
    expect(class_uses_recursive($class))->toHaveKey(RespectsPreferences::class);

    /** @var Notification&object{notificationCategory: callable} $notification */
    $notification = bareNotification($class);

    expect(NotificationCategories::exists($notification->notificationCategory()))->toBeTrue();
})->with(fn (): array => platformNotificationClasses());

it('silences certificate and commencement mail when project lifecycle mail is muted', function (string $class) {
    (new SaveNotificationPreferences)($this->officer, [
        NotificationCategories::PROJECTS => ['mail' => false, 'database' => true],
    ]);

    expect(bareNotification($class)->via($this->officer->fresh()))->toBe(['database']);
})->with([
    CompletionCertificateIssued::class,
    CommencementNoticeIssued::class,
    CommencementNoticeOverdue::class,
]);

it('gives work plans a switch of their own, and honours it', function (string $class) {
    expect(NotificationCategories::isMutable(NotificationCategories::WORKPLANS))->toBeTrue();

    (new SaveNotificationPreferences)($this->officer, [
        NotificationCategories::WORKPLANS => ['mail' => false, 'database' => false],
    ]);

    expect(bareNotification($class)->via($this->officer->fresh()))->toBe([])
        // Muting work plans leaves every other category untouched.
        ->and($this->officer->fresh()->hasMutedNotifications(NotificationCategories::PROJECTS, 'mail'))->toBeFalse();
})->with([
    WorkplanChainUpdated::class,
    WorkplanActivityAssigned::class,
    WorkplanActivityOverdue::class,
]);
