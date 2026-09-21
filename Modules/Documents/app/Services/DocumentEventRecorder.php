<?php

namespace Modules\Documents\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Modules\Documents\Enums\DocumentEventType;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentEvent;
use Modules\Documents\Models\DocumentVersion;

class DocumentEventRecorder
{
    /** @param array<string, mixed> $detail */
    public function record(
        Document $document,
        DocumentEventType $type,
        ?User $actor,
        array $detail,
        string $sourceType,
        string $sourceId,
        ?DocumentVersion $version = null,
        ?CarbonInterface $occurredAt = null,
        string $origin = 'recorded',
    ): DocumentEvent {
        return DocumentEvent::create([
            'document_id' => $document->id,
            'institution_id' => $document->institution_id,
            'type' => $type,
            'actor_user_id' => $this->validActor($document, $actor)?->id,
            'actor_name' => $this->validActor($document, $actor)?->name,
            'version_id' => $version?->id,
            'detail' => $detail,
            'origin' => $origin,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    private function validActor(Document $document, ?User $actor): ?User
    {
        return $actor && (string) $actor->institution_id === (string) $document->institution_id
            ? $actor
            : null;
    }

    /** @return array{id: string, version_number: int, filename: string} */
    public static function versionSnapshot(DocumentVersion $version): array
    {
        return [
            'id' => $version->id,
            'version_number' => (int) $version->version_number,
            'filename' => $version->filename,
        ];
    }
}
