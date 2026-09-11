<?php

namespace Modules\Documents\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Documents\Exceptions\DocumentResponsibilityException;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentResponsibleHistory;

class UpdateDocumentResponsibilityAction
{
    public function execute(User $actor, string $documentId, ?string $responsibleId, int $expectedRevision): Document
    {
        return DB::transaction(function () use ($actor, $documentId, $responsibleId, $expectedRevision): Document {
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
                return $document->load('responsibleUser:id,name,active,deleted_at');
            }

            $previous = $document->responsibleUser()->first();
            $revision = (int) $document->responsibility_revision + 1;
            $document->responsible_user_id = $responsible?->id;
            $document->responsibility_revision = $revision;
            $document->save();

            DocumentResponsibleHistory::create([
                'document_id' => $document->id,
                'previous_responsible_user_id' => $previous?->id,
                'new_responsible_user_id' => $responsible?->id,
                'actor_user_id' => $actor->id,
                'previous_responsible_name' => $previous?->name,
                'new_responsible_name' => $responsible?->name,
                'revision' => $revision,
            ]);

            return $document->load('responsibleUser:id,name,active,deleted_at');
        });
    }
}
