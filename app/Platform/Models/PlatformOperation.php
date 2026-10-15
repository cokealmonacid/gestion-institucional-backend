<?php

namespace App\Platform\Models;

class PlatformOperation extends PlatformRecord
{
    protected function casts(): array
    {
        return ['result' => 'array', 'committed_at' => 'datetime'];
    }
}
