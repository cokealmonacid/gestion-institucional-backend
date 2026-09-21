<?php

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Documents\Enums\DocumentEventType;

class DocumentEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = $this->detail ?? [];

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'occurred_at' => $this->occurred_at,
            'actor' => $this->publicActor(),
            'version' => $this->publicVersion($detail['version'] ?? null),
            'detail' => $this->publicDetail($detail),
        ];
    }

    /** @return array{id: string, name: string, active: bool}|null */
    private function publicActor(): ?array
    {
        $actor = $this->actor;
        if (! $actor || (string) $actor->institution_id !== (string) $this->institution_id) {
            return null;
        }

        return [
            'id' => $actor->id,
            'name' => $this->origin === 'legacy' ? $actor->name : $this->actor_name,
            'active' => (bool) $actor->active && ! $actor->trashed(),
        ];
    }

    /** @return array{id: string, version_number: int, filename: string}|null */
    private function publicVersion(mixed $version): ?array
    {
        if (! is_array($version)
            || ! is_string($version['id'] ?? null)
            || ! is_numeric($version['version_number'] ?? null)
            || ! is_string($version['filename'] ?? null)) {
            return null;
        }

        return [
            'id' => $version['id'],
            'version_number' => (int) $version['version_number'],
            'filename' => $version['filename'],
        ];
    }

    /** @param array<string, mixed> $detail */
    private function publicDetail(array $detail): array
    {
        return match ($this->type) {
            DocumentEventType::Created => [],
            DocumentEventType::VersionUploaded => [
                'became_current' => is_bool($detail['became_current'] ?? null)
                    ? $detail['became_current'] : null,
            ],
            DocumentEventType::CurrentVersionChanged => [
                'previous_version' => $this->publicVersion($detail['previous_version'] ?? null),
                'new_version' => $this->publicVersion($detail['new_version'] ?? ($detail['version'] ?? null)),
            ],
            DocumentEventType::ResponsibleAssigned,
            DocumentEventType::ResponsibleChanged,
            DocumentEventType::ResponsibleRemoved => [
                'previous_responsible_name' => is_string($detail['previous_responsible_name'] ?? null)
                    ? $detail['previous_responsible_name'] : null,
                'new_responsible_name' => is_string($detail['new_responsible_name'] ?? null)
                    ? $detail['new_responsible_name'] : null,
            ],
        };
    }
}
