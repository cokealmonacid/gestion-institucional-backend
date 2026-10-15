<?php

namespace App\Console\Commands;

use App\Platform\Services\PlatformReadiness;
use Illuminate\Console\Command;

class PlatformPreflight extends Command
{
    protected $signature = 'platform:preflight';

    protected $description = 'Read-only schema/data/configuration readiness report; never deploys or repairs data';

    public function handle(PlatformReadiness $readiness): int
    {
        try {
            $report = $readiness->inspect();
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $report['data_preflight_passed'] && ! in_array(false, $report['local_configuration_checks'], true) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $this->error('Read-only readiness inspection could not complete. No repair was attempted.');

            return self::FAILURE;
        }
    }
}
