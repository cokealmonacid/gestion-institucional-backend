<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Institution\Models\Institution;

class Tag extends Model
{
    use HasUuids;

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
