<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UserInvitation extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'token_hash',
        'generation',
        'delivery_status',
        'expires_at',
        'last_sent_at',
        'delivery_attempted_at',
        'provider_accepted_at',
        'failed_at',
        'consumed_at',
        'revoked_at',
        'institution_auth_epoch',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'delivery_attempted_at' => 'datetime',
            'provider_accepted_at' => 'datetime',
            'failed_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'generation' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
