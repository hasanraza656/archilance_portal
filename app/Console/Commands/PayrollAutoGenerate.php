<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PayrollGenerator;
use App\Support\PayrollSchema;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Creates the new month's payroll as a DRAFT, so it is waiting for finance on
 * the first working morning instead of someone having to remember.
 *
 * A draft only: nothing is approved or paid automatically. Idempotent — if the
 * month already has a live run (made by hand or by an earlier run of this
 * command) it does nothing, so the scheduler can safely call it every day and a
 * missed day just gets caught up the next morning.
 */
class PayrollAutoGenerate extends Command
{
    protected $signature = 'payroll:auto-generate {--month= : YYYY-MM, defaults to the current month}';
    protected $description = 'Create this month\'s payroll draft if it does not exist yet.';

    public function handle(PayrollGenerator $generator)
    {
        if (!PayrollSchema::table('payroll_runs') || !PayrollSchema::table('salary_profiles')) {
            $this->info('Payroll tables are not installed yet — nothing to do.');
            return self::SUCCESS;
        }

        $month = $this->option('month')
            ? Carbon::parse($this->option('month') . '-01')->startOfMonth()
            : Carbon::today()->startOfMonth();

        $result = $generator->generate($month, null, 'Generated automatically at the start of the month.', true, 'auto');

        if ($result['status'] !== 'ok') {
            $this->info($result['message']);
            return self::SUCCESS;
        }

        $run = $result['run'];
        $this->info("Payroll draft created for {$month->format('F Y')} ({$run->employee_count} employees).");

        // Tell the people who run payroll, in the portal.
        try {
            $recipients = User::where(function ($q) {
                $q->where('user_role', 2)->orWhere(fn ($w) => $w->where('user_role', 3)->where('employee_type', 'Executive'));
            })->get(['id']);

            foreach ($recipients as $u) {
                insertNotificationWithNature(
                    $u->id,
                    $u->id,
                    'payroll_generated',
                    "Payroll for {$month->format('F Y')} is ready for review ({$run->employee_count} employees).",
                    'info',
                    0
                );
            }
        } catch (\Throwable $e) {
            // courtesy only
        }

        return self::SUCCESS;
    }
}
