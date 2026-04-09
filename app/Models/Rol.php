<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use App\Enums\RoleType;

class Rol extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $table = 'roles';

    protected $casts = [
        'type' => RoleType::class,
    ];

    protected $fillable = [
        'type',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'role_id');
    }
}
