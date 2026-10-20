<?php

namespace App\Platform\Authorization;

enum PlatformPermission: string
{
    case Metadata = 'platform.metadata';
    case Plans = 'platform.plans';
    case Contracts = 'platform.contracts';
    case Audit = 'platform.audit';
    case Operations = 'platform.operations';
    case AdministratorAccess = 'platform.administrator_access';
    case AccessRecovery = 'platform.access_recovery';
    case UserAccess = 'platform.user_access';
}
