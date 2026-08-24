<?php

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Documents\Services\DocumentLifecycleAccess;

class DocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currentVersion = $this->relationLoaded('currentActiveVersion')
            ? $this->currentActiveVersion
            : null;
        $canMutate = (bool) $request->attributes->get('document_lifecycle_can_mutate', false);
        $canDownload = app(DocumentLifecycleAccess::class)->canDownload($currentVersion);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'responsible_unit' => $this->responsible_unit,
            'status' => $this->status,
            'author_id' => $this->author_id,
            'institution_id' => $this->institution_id,
            'node_id' => $this->node_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'lifecycle' => [
                'version_count' => (int) ($this->active_versions_count ?? 0),
                'has_current_version' => $currentVersion !== null,
                'capabilities' => [
                    'can_download' => $canDownload,
                    'can_upload_version' => $canMutate,
                ],
            ],
        ];
    }
}
