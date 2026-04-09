<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentDownload extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $table = 'document_downloads';

    protected $fillable = [
        'document_id',
        'document_version_id',
        'user_id',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function version()
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
