<?php

namespace App\Console\Commands;

class RecoverPlatformMfa extends PlatformOperatorCommand
{
    protected $signature = 'platform:operator:recover-mfa {--operator-id=} {--operation-id=} {--reason=} {--accountability=} {--identity-verified}';

    protected $description = 'Operations recovery after external identity verification; mandatory MFA reenrollment follows.';

    protected string $mode = 'recover-mfa';
}
