<?php

namespace App\Console\Commands;

use App\Services\OffboardingService;
use Illuminate\Console\Command;

/**
 * Switches off the account of everyone whose offboarding day has come. Idempotent, so the scheduler can run it daily
 * and a missed day is simply caught up the next time (the offboarding screen also does this when opened).
 */
class OffboardingProcess extends Command
{
    protected $signature = 'offboarding:process';
    protected $description = 'Switch off the accounts of employees whose offboarding date has arrived.';

    public function handle(OffboardingService $service)
    {
        $n = $service->processDue();
        $this->info($n ? "{$n} account(s) switched off." : 'Nothing due.');

        return self::SUCCESS;
    }
}
