<?php

namespace App\Console\Commands;

class BootstrapPlatformOperator extends PlatformOperatorCommand
{
    protected $signature = 'platform:operator:bootstrap {--name=} {--email=} {--operation-id=} {--reason=} {--accountability=}';

    protected $description = 'Provision the first pending platform operator; no administrative session is issued.';

    protected string $mode = 'bootstrap';
}
