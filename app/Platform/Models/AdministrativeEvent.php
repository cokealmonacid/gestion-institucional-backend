<?php

namespace App\Platform\Models;

use LogicException;

class AdministrativeEvent extends PlatformRecord
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Administrative history is immutable.'));
        static::deleting(fn () => throw new LogicException('Administrative history is immutable.'));
    }
}
