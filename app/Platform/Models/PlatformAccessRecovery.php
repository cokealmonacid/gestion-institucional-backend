<?php

namespace App\Platform\Models;

class PlatformAccessRecovery extends PlatformRecord
{
    protected $attributes = ['revision' => 1, 'generation' => 1, 'publication_status' => 'pending'];

    protected $hidden = ['verification_hash', 'prepared_password', 'fingerprint', 'reserved_email'];

    protected function casts(): array
    {
        return ['request' => 'array', 'snapshot' => 'array', 'result' => 'array', 'expires_at' => 'datetime',
            'verified_at' => 'datetime', 'published_at' => 'datetime', 'revision' => 'integer', 'generation' => 'integer'];
    }
}
