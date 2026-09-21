<?php

namespace Modules\Documents\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Documents\Enums\DocumentEventType;
use Modules\Documents\Exceptions\DocumentResponsibilityException;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentResponsibleHistory;
use Modules\Documents\Services\DocumentEventRecorder;

class UpdateDocumentResponsibilityAction
{
    public function execute(
        User $actor,
        string $documentId,
        ?string $responsibleId,
        int $expectedRevision,
        DocumentEventRecorder $events,
    ): Document {
        return DB::transaction(function () use ($actor, $documentId, $responsibleId, $expectedRevision, $events): Document {
            $document = Document::query()->where('institution_id', $actor->institution_id)
                ->where('status', true)->lockForUpdate()->find($documentId);

            if (! $document) {
                throw new DocumentResponsibilityException('DOCUMENT_NOT_AVAILABLE', 'The document is not available.', 404);
            }
            if ((int) $document->responsibility_revision !== $expectedRevision) {
                throw new DocumentResponsibilityException('DOCUMENT_RESPONSIBILITY_CONFLICT', 'The document responsibility has changed.', 409);
            }

            $responsible = $responsibleId === null ? null : User::query()
                ->where('institution_id', $actor->institution_id)->where('active', true)->find($responsibleId);
            if ($responsibleId !== null && ! $responsible) {
                throw new DocumentResponsibilityException('DOCUMENT_RESPONSIBLE_NOT_AVAILABLE', 'The selected responsible user is not available.', 404);
            }
            if ((string) $document->responsible_user_id === (string) $responsibleId) {
                return $document->load('responsibleUser:id,name,active,deleted_at,institution_id');
            }

            $previous = $document->responsibleUser()->first();
            $revision = (int) $document->responsibility_revision + 1;
            $document->responsible_user_id = $responsible?->id;
            $document->responsibility_revision = $revision;
            $document->save();

            $history = DocumentResponsibleHistory::create([
                'document_id' => $document->id,
                'previous_responsible_user_id' => $previous?->id,
                'new_responsible_user_id' => $responsible?->id,
                'actor_user_id' => $actor->id,
                'previous_responsible_name' => $previous?->name,
                'new_responsible_name' => $responsible?->name,
                'revision' => $revision,
            ]);

            $type = $previous === null
                ? DocumentEventType::ResponsibleAssigned
                : ($responsible === null
                    ? DocumentEventType::ResponsibleRemoved
                    : DocumentEventType::ResponsibleChanged);
            $events->record($document, $type, $actor, [
                'previous_responsible_name' => $previous?->name,
                'new_responsible_name' => $responsible?->name,
            ], 'document_responsibility', $history->id, occurredAt: $history->created_at);

            return $document->load('responsibleUser:id,name,active,deleted_at,institution_id');
        });
    }
}
