<?php

namespace Modules\Institution\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Documents\Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Modules\Institution\Models\Institution;

class Tag extends Model
{
    use HasUuids, HasFactory;

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }

    public $incrementing = false;

    protected $fillable = [
        'name',
        'description',
        'status',
        'institution_id',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function documents()
    {
        return $this->belongsToMany(Document::class, 'document_tags', 'tag_id', 'document_id')
            ->withPivot('assigned_by_id')
            ->withTimestamps();
    }
}

