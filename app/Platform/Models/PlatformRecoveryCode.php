<?php

namespace App\Platform\Models;

class PlatformRecoveryCode extends PlatformRecord
{
    protected $hidden = ['code_hash'];
}
