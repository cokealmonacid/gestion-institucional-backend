<?php

namespace App\Platform\Models;

class InstitutionContract extends PlatformRecord
{
    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }
}
