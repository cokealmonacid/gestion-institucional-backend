<?php

namespace Modules\Nodes\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NodeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'path' => $this->path,
            'depth' => $this->depth,
            'order' => (int) $this->order,
            'active' => $this->active,
            'institution_id' => $this->institution_id,
            'parent_id' => $this->parent_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'has_children' => $this->has_children,
        ];
    }
}
