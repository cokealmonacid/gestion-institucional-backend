<?php

namespace App\Console\Commands;

class DeactivatePlatformOperator extends PlatformOperatorCommand
{
    protected $signature = 'platform:operator:deactivate {--operator-id=} {--operation-id=} {--reason=} {--accountability=} {--confirm}';

    protected $description = 'Deactivate an identified platform operator and revoke access, preserving another usable operator.';

    protected string $mode = 'deactivate';
}
