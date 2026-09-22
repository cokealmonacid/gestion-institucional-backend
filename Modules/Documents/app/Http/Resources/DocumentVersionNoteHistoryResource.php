<?php

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentVersionNoteHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'previous_note' => $this->previous_comment,
            'new_note' => $this->new_comment,
            'actor' => (new DocumentVersionNoteActorResource($this->resource))->resolve($request),
            'occurred_at' => $this->created_at,
        ];
    }
}
