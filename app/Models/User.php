<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Documents\Models\Document;
use Modules\Institution\Models\Institution;

#[Fillable(['name', 'email', 'password', 'institution_id', 'active'])]
#[Hidden(['password', 'remember_token', 'access_revision'])]
class User extends Authenticatable
{
    use HasApiTokens { createToken as private createInstitutionToken; }

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deleted_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    public function createToken(string $name, array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null)
    {
        $issued = $this->createInstitutionToken($name, $abilities, $expiresAt);
        $issued->accessToken->forceFill(['institution_auth_epoch' => Institution::whereKey($this->institution_id)->value('auth_epoch')])->save();

        return $issued;
    }

    public function roles()
    {
        return $this->belongsToMany(Rol::class, 'role_user', 'user_id', 'role_id')->using(RoleUser::class)->withTimestamps();
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function invitation()
    {
        return $this->hasOne(UserInvitation::class);
    }

    public function responsibleDocuments()
    {
        return $this->hasMany(Document::class, 'responsible_user_id');
    }
}
