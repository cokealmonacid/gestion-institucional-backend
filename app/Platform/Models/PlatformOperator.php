<?php

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class PlatformOperator extends Authenticatable
{
    use HasApiTokens, HasUuids;

    protected $guarded = [];

    protected $attributes = ['active' => true, 'auth_epoch' => 0, 'last_totp_step' => -1];

    protected $hidden = ['password', 'totp_secret', 'pending_totp_secret'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'password' => 'hashed', 'totp_secret' => 'encrypted', 'pending_totp_secret' => 'encrypted', 'verified_at' => 'datetime', 'mfa_confirmed_at' => 'datetime', 'enrollment_expires_at' => 'datetime', 'auth_epoch' => 'integer', 'last_totp_step' => 'integer'];
    }

    public function summary(): array
    {
        return $this->only(['id', 'name', 'email']);
    }
}
