<?php

namespace App\Console\Commands;

class ReissuePlatformAccess extends PlatformOperatorCommand
{
    protected $signature = 'platform:operator:reissue-access {--operator-id=} {--operation-id=} {--reason=} {--accountability=}';

    protected $description = 'Revoke access and issue a new operator activation or password-reset link.';

    protected string $mode = 'reissue-access';
}
