<?php

namespace Modules\Documents\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Documents\Enums\DocumentEventType;
use Modules\Documents\Exceptions\DocumentRenameException;
use Modules\Documents\Models\Document;
use Modules\Documents\Services\DocumentEventRecorder;
use Modules\Documents\Support\DocumentName;
use Modules\Nodes\Models\Node;

class UpdateDocumentAction
{
    /** @param array{name?: string, description?: ?string, category?: ?string, responsible_unit?: ?string} $attributes */
    public function execute(
        User $actor,
        string $documentId,
        array $attributes,
        DocumentEventRecorder $events,
    ): Document {
        return DB::transaction(function () use ($actor, $documentId, $attributes, $events): Document {
            $document = Document::query()
                ->where('institution_id', $actor->institution_id)
                ->lockForUpdate()
                ->find($documentId);

            if ($document === null) {
                throw $this->notAvailable();
            }

            $nameChanged = false;
            $previousName = $document->name;

            if (array_key_exists('name', $attributes)) {
                if (! $document->status || ! $this->locationIsActive($document)) {
                    throw $this->notAvailable();
                }

                $newName = DocumentName::canonicalize($attributes['name']);
                $currentName = DocumentName::canonicalize($document->name);
                $nameChanged = $currentName !== $newName;
                $attributes['name'] = $newName;

                if (! $nameChanged) {
                    unset($attributes['name']);
                }
            }

            if ($attributes !== []) {
                $document->fill($attributes);
                $document->save();
            }

            if ($nameChanged) {
                $events->record(
                    $document,
                    DocumentEventType::Renamed,
                    $actor,
                    ['previous_name' => $previousName, 'new_name' => $document->name],
                    'document_rename',
                    (string) Str::uuid(),
                    occurredAt: $document->updated_at,
                );
            }

            return $document;
        });
    }

    private function locationIsActive(Document $document): bool
    {
        $node = Node::query()
            ->where('institution_id', $document->institution_id)
            ->lockForUpdate()
            ->find($document->node_id);

        if ($node === null) {
            return false;
        }

        $pathIds = explode('/', $node->path);

        return Node::query()
            ->where('institution_id', $document->institution_id)
            ->whereIn('id', $pathIds)
            ->where('active', true)
            ->lockForUpdate()
            ->count() === count($pathIds);
    }

    private function notAvailable(): DocumentRenameException
    {
        return new DocumentRenameException('DOCUMENT_NOT_AVAILABLE', 'The document is not available.', 404);
    }
}
