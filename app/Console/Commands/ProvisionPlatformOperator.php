<?php

namespace App\Console\Commands;

class ProvisionPlatformOperator extends PlatformOperatorCommand
{
    protected $signature = 'platform:operator:provision {--name=} {--email=} {--operation-id=} {--reason=} {--accountability=}';

    protected $description = 'Explicitly provision an additional pending platform operator.';

    protected string $mode = 'provision';
}
