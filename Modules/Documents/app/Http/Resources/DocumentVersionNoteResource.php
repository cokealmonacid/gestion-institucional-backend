<?php

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentVersionNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $history = $this->latestCommentHistory;

        return [
            'version' => [
                'id' => $this->id,
                'version_number' => (int) $this->version_number,
                'filename' => $this->filename,
                'active' => (bool) $this->active,
                'is_current' => (bool) $this->is_current,
            ],
            'note' => $this->comment,
            'last_changed_at' => $history?->created_at,
            'actor' => $history
                ? (new DocumentVersionNoteActorResource($history))->resolve($request)
                : null,
        ];
    }
}
