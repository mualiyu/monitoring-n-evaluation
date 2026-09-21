<?php

namespace App\Notifications\Workplans;

use App\Enums\WorkplanStatus;
use App\Models\Workplan;
use App\Support\SurfaceUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "The 2026 work plan is waiting for your approval." / "Your work plan was
 * sent back." Database + mail, per the notification rules; SMS/WhatsApp
 * arrive behind the same abstraction when the preferences table lands.
 *
 * ONE parameterized class for the whole chain rather than four near-identical
 * ones, as ProgressReportChainUpdated already does: *who* hears about a step
 * is a property of the step, decided in the job — not of the message body.
 *
 * NOT queued itself: it is already sent from inside a queued, tenant-aware
 * job, and queueing it again would hand the mailer a second, tenant-less hop.
 */
class WorkplanChainUpdated extends Notification
{
    public function __construct(
        private readonly Workplan $workplan,
        private readonly WorkplanStatus $to,
        private readonly ?string $reason = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Work plan :year for :entity is now :status', [
                'year' => $this->workplan->yearLabel(),
                'entity' => $this->workplan->tenant->name,
                'status' => $this->to->label(),
            ]))
            ->greeting(__('Hello,'))
            ->line(__('The :year annual work plan ":title" is now :status.', [
                'year' => $this->workplan->yearLabel(),
                'title' => $this->workplan->title,
                'status' => $this->to->label(),
            ]))
            ->line($this->callToAction());

        if ($this->reason !== null && $this->reason !== '') {
            $message->line(__('Reason given: :reason', ['reason' => $this->reason]));
        }

        // The workspace, not a deep link: a Livewire route arrives with its
        // own slice, and a 404 in a permanent secretary's inbox is worse than
        // one click too many.
        return $message->action(__('Open the workspace'), SurfaceUrl::base($this->workplan->tenant));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'workplan.chain_updated',
            'workplan_ulid' => $this->workplan->ulid,
            'workplan_title' => $this->workplan->title,
            'workplan_status' => $this->to->value,
            'workplan_year' => $this->workplan->yearLabel(),
            'tenant_id' => $this->workplan->tenant_id,
            'reason' => $this->reason,
        ];
    }

    private function callToAction(): string
    {
        return match ($this->to) {
            WorkplanStatus::Submitted => __('It is waiting for your approval.'),
            WorkplanStatus::Approved => __('It has been approved. Its activities are now frozen; record progress against them as the year runs.'),
            WorkplanStatus::Active => __('It is now the plan being delivered.'),
            WorkplanStatus::Rejected => __('It has been sent back for revision — please correct it and resubmit.'),
            WorkplanStatus::Closed => __('The year has been closed. Its record stays available for reporting and evaluation.'),
            WorkplanStatus::Draft => __('It has been reopened.'),
        };
    }
}
