<?php

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version_number' => (int) $this->version_number,
            'filename' => $this->filename,
            'mime_type' => $this->mime_type,
            'file_size' => (int) $this->file_size,
            'active' => (bool) $this->active,
            'is_current' => (bool) $this->is_current,
            'author' => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
