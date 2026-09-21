<?php

namespace Modules\Documents\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Documents\Enums\DocumentEventType;

class DocumentEvent extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $fillable = [
        'document_id', 'institution_id', 'type', 'actor_user_id', 'actor_name',
        'version_id', 'detail', 'origin', 'source_type', 'source_id', 'occurred_at',
    ];

    protected $hidden = ['institution_id', 'origin', 'source_type', 'source_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'type' => DocumentEventType::class,
            'detail' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Document events are append-only.'));
        static::deleting(fn () => throw new LogicException('Document events are append-only.'));
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
