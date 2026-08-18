<?php

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Services\DocumentLifecycleAccess;

class DocumentLifecycleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currentVersion = $this->versions
            ->first(fn ($version) => $version->active && $version->is_current);
        $canMutate = app(DocumentLifecycleAccess::class)->canMutate($request->user());
        $canDownload = $currentVersion !== null
            && $currentVersion->url
            && Storage::disk(config('filesystems.default'))->exists($currentVersion->url);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'responsible_unit' => $this->responsible_unit,
            'status' => (bool) $this->status,
            'author_id' => $this->author_id,
            'institution_id' => $this->institution_id,
            'node_id' => $this->node_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'author' => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ] : null,
            'location' => [
                'id' => $this->node->id,
                'name' => $this->node->name,
                'path' => $this->node->path,
            ],
            'current_version' => $currentVersion
                ? (new DocumentVersionResource($currentVersion))->resolve($request)
                : null,
            'version_count' => $this->versions->where('active', true)->count(),
            'capabilities' => [
                'can_download' => $canDownload,
                'can_upload_version' => $canMutate,
                'can_mark_version_current' => $canMutate,
            ],
        ];
    }
}
