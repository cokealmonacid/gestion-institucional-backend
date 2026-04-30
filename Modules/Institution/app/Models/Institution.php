<?php

namespace Modules\Institution\Models;

use App\Models\Document;
use App\Models\Node;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $table = 'institutions';

    protected $fillable = ['name', 'status'];

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
