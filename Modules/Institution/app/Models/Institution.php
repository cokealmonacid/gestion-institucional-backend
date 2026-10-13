<?php

namespace Modules\Institution\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Documents\Models\Document;
use Modules\Institution\Database\Factories\InstitutionFactory;
use Modules\Nodes\Models\Node;

class Institution extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): InstitutionFactory
    {
        return InstitutionFactory::new();
    }

    public $incrementing = false;

    protected $table = 'institutions';

    protected $fillable = ['name', 'status'];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    public function nodes()
    {
        return $this->hasMany(Node::class, 'institution_id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'institution_id');
    }

    public function tags()
    {
        return $this->hasMany(Tag::class, 'institution_id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'institution_id');
    }
}
