<?php

namespace App\Platform\Models;

class PlatformAuthChallenge extends PlatformRecord
{
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'attempts' => 'integer', 'auth_epoch' => 'integer'];
    }
}
