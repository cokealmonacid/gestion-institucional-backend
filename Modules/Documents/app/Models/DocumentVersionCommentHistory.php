<?php

namespace Modules\Documents\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentVersionCommentHistory extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $table = 'document_version_comment_histories';

    protected $fillable = [
        'document_id',
        'document_version_id',
        'user_id',
        'previous_comment',
        'new_comment',
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
