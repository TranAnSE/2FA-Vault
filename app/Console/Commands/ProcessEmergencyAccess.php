<?php

namespace App\Console\Commands;

use App\Services\EmergencyAccessService;
use Illuminate\Console\Command;

class ProcessEmergencyAccess extends Command
{
    protected $signature   = 'emergency:process';
    protected $description = 'Process expired emergency access requests and check dead man\'s switches';

    public function handle(EmergencyAccessService $service): int
    {
        $expired   = $service->processExpiredRequests();
        $triggered = $service->checkDeadMansSwitch();

        $this->info("Emergency access: {$expired} requests auto-granted, {$triggered} dead man's switches triggered.");

        return self::SUCCESS;
    }
}
