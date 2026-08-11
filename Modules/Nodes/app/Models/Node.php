<?php

namespace Modules\Nodes\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Documents\Models\Document;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Database\Factories\NodesFactory;
use Modules\Nodes\Support\NodeName;

class Node extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): NodesFactory
    {
        return NodesFactory::new();
    }

    public $incrementing = false;

    protected $table = 'nodes';

    protected $fillable = [
        'name',
        'normalized_name',
        'name_fingerprint',
        'parent_scope',
        'path',
        'depth',
        'order',
        'active',
        'institution_id',
        'parent_id',
    ];

    protected $hidden = [
        'normalized_name',
        'name_fingerprint',
        'parent_scope',
    ];

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Node $node): void {
            if (! $node->id) {
                $node->id = $node->newUniqueId();
            }

            if (! $node->path) {
                $node->path = $node->parent_id === null
                    ? $node->id
                    : Node::query()->findOrFail($node->parent_id)->path.'/'.$node->id;
            }
        });

        static::saving(function (Node $node): void {
            if ($node->isDirty('name') || ! $node->normalized_name || ! $node->name_fingerprint) {
                $name = NodeName::normalize($node->name);
                $node->name = $name['display'];
                $node->normalized_name = $name['normalized'];
                $node->name_fingerprint = $name['fingerprint'];
            }

            if ($node->isDirty('parent_id') || ! $node->parent_scope) {
                $node->parent_scope = $node->parent_id === null ? 'R' : 'P:'.$node->parent_id;
            }
        });
    }

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

    public function canContainChildren(): bool
    {
        // Node is the canonical container resource; no non-container subtype exists today.
        return true;
    }
}
