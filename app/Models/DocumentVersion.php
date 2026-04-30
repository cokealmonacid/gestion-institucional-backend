<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;

class DocumentVersion extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $table = 'document_versions';

    protected $fillable = [
        'version_number',
        'url',
        'filename',
        'mime_type',
        'file_size',
        'comment',
        'author_id',
        'document_id',
        'institution_id',
        'node_id',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

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

    public function downloads()
    {
        return $this->hasMany(DocumentDownload::class, 'document_version_id');
    }
}
