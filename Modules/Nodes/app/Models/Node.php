<?php

namespace Modules\Nodes\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Documents\Models\Document;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Database\Factories\NodesFactory;

class Node extends Model
{
    use HasUuids, HasFactory;

    protected static function newFactory(): NodesFactory
    {
        return NodesFactory::new();
    }

    public $incrementing = false;

    protected $table = 'nodes';

    protected $fillable = [
        'name',
        'path',
        'depth',
        'order',
        'active',
        'institution_id',
        'parent_id',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function parent()
    {
        return $this->belongsTo(Node::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Node::class, 'parent_id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'node_id');
    }
}
