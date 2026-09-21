<?php

namespace Modules\Documents\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentResponsibleHistory extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $fillable = [
        'document_id', 'previous_responsible_user_id', 'new_responsible_user_id',
        'actor_user_id', 'previous_responsible_name', 'new_responsible_name', 'revision',
    ];

    protected $casts = ['revision' => 'integer'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function previousResponsibleUser()
    {
        return $this->belongsTo(User::class, 'previous_responsible_user_id')->withTrashed();
    }

    public function newResponsibleUser()
    {
        return $this->belongsTo(User::class, 'new_responsible_user_id')->withTrashed();
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
