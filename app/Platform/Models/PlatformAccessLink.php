<?php

namespace App\Platform\Models;

class PlatformAccessLink extends PlatformRecord
{
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'generation' => 'integer'];
    }
}
