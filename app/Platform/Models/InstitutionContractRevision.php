<?php

namespace App\Platform\Models;

use LogicException;

class InstitutionContractRevision extends PlatformRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['conditions' => 'array', 'effective_at' => 'immutable_datetime', 'revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Agreements are immutable.'));
        static::deleting(fn () => throw new LogicException('Agreements are immutable.'));
    }
}
