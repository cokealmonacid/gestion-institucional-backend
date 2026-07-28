<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use App\Enums\RoleType;

class Rol extends Model
{
    use HasUuids, HasFactory;

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
        return $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id')->using(RoleUser::class)->withTimestamps();
    }
}
