<?php

namespace App\Platform\Models;

class CatalogPlan extends PlatformRecord
{
    protected function casts(): array
    {
        return ['limits' => 'array', 'available_for_new_contracts' => 'boolean', 'revision' => 'integer'];
    }
}
