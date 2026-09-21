<?php

namespace App\Jobs\Issues;

use App\Enums\ExceptionTrigger;
use App\Jobs\Concerns\TenantAware;
use App\Jobs\Issues\Concerns\ResolvesIssueRecipients;
use App\Models\ExceptionReport;
use App\Models\User;
use App\Notifications\Issues\ExceptionReportRaised;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * A deviation has been recorded against a project. The MDA admin always hears;
 * state oversight hears as well when the trigger is a CRITICAL INCIDENT.
 *
 * That split is the manual's reporting chain, not a preference: a schedule
 * slippage is the ministry's business to explain at the next review, while a
 * critical incident on a public work is the secretariat's business
 * immediately. Routing every automated deviation to the state would make the
 * one notice that must never be missed indistinguishable from nightly noise.
 *
 * Ids, not models — see NotifyIssueAssigned.
 */
class NotifyExceptionReportRaised implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ResolvesIssueRecipients, SerializesModels, TenantAware;

    public function __construct(private readonly int $reportId)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $report = ExceptionReport::query()->with(['project', 'project.tenant'])->find($this->reportId);

        if ($report === null) {
            return;
        }

        $recipients = $this->mdaAdmins();

        if ($report->trigger === ExceptionTrigger::CriticalIncident) {
            $recipients = $recipients->concat($this->stateOversight())->unique('id')->values();
        }

        $recipients = $recipients
            // The person who filed it knows; telling them is noise, and noise
            // is how people learn to ignore this channel.
            ->reject(fn (User $user): bool => $user->id === $report->raised_by_id)
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new ExceptionReportRaised($report));
    }
}
