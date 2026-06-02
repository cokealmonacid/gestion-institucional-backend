<?php

namespace Modules\Documents\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Institution\Models\Institution;
use Modules\Institution\Models\Tag;
use Modules\Nodes\Models\Node;

class Document extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $table = 'documents';

    protected $fillable = [
        'name',
        'description',
        'category',
        'responsible_unit',
        'status',
        'author_id',
        'institution_id',
        'node_id',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function node()
    {
        return $this->belongsTo(Node::class, 'node_id');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'document_tags', 'document_id', 'tag_id')
            ->withPivot('assigned_by_id')
            ->withTimestamps();
    }

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class, 'document_id');
    }

    public function downloads()
    {
        return $this->hasMany(DocumentDownload::class, 'document_id');
    }
}
