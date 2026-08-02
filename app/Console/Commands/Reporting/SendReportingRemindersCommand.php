<?php

namespace App\Console\Commands\Reporting;

use App\Actions\Reporting\SendDeadlineReminders;
use Illuminate\Console\Command;

/**
 * The morning reminder sweep. Safe to run twice in one day: every send is
 * gated by the obligation's monotonic reminder counter, advanced under a row
 * lock in the same transaction as the dispatch.
 */
class SendReportingRemindersCommand extends Command
{
    protected $signature = 'reporting:send-reminders';

    protected $description = 'Send due-soon reminders for outstanding report obligations (idempotent per rung)';

    public function handle(SendDeadlineReminders $send): int
    {
        $count = $send();

        $this->info("{$count} reminder(s) dispatched.");

        return self::SUCCESS;
    }
}
