<?php

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentVersionNoteActorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user?->id,
            'name' => $this->actor_name ?? $this->user?->name,
            'active' => $this->user
                ? (bool) $this->user->active && ! $this->user->trashed()
                : false,
        ];
    }
}
