<?php

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

abstract class PlatformRecord extends Model
{
    use HasUuids;

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';
}
