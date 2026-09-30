<?php

namespace Modules\Documents\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Documents\Exceptions\DocumentResponsibilityException;
use Modules\Documents\Models\Document;
use Modules\Documents\Services\DocumentEventRecorder;
use Modules\Documents\Services\DocumentResponsibilityWriter;
use Modules\Institution\Models\Institution;
use Modules\Institution\Services\RoleChangeImpact;

class UpdateDocumentResponsibilityAction
{
    public function execute(
        User $actor,
        string $documentId,
        ?string $responsibleId,
        int $expectedRevision,
        DocumentEventRecorder $events,
        RoleChangeImpact $impact,
        DocumentResponsibilityWriter $writer,
    ): Document {
        return DB::transaction(function () use ($actor, $documentId, $responsibleId, $expectedRevision, $events, $impact, $writer): Document {
            Institution::query()->whereKey($actor->institution_id)->lockForUpdate()->firstOrFail();

            $document = Document::query()->where('institution_id', $actor->institution_id)
                ->where('status', true)->lockForUpdate()->find($documentId);

            if (! $document) {
                throw new DocumentResponsibilityException('DOCUMENT_NOT_AVAILABLE', 'The document is not available.', 404);
            }
            if ((int) $document->responsibility_revision !== $expectedRevision) {
                throw new DocumentResponsibilityException('DOCUMENT_RESPONSIBILITY_CONFLICT', 'The document responsibility has changed.', 409);
            }

            if ((string) $document->responsible_user_id === (string) $responsibleId) {
                $current = $document->responsibleUser()->first();
                if ($responsibleId === null || ($current && (string) $current->institution_id === (string) $actor->institution_id)) {
                    return $document->setRelation('responsibleUser', $current);
                }
            }

            $responsible = $responsibleId === null ? null : User::query()
                ->where('institution_id', $actor->institution_id)->where('active', true)
                ->lockForUpdate()->find($responsibleId);
            if ($responsibleId !== null && (! $responsible || ! $impact->isEligible($responsible))) {
                throw new DocumentResponsibilityException('DOCUMENT_RESPONSIBLE_NOT_AVAILABLE', 'The selected responsible user is not available.', 404);
            }
            $writer->write($document, $responsible, $actor, $events);

            return $document->load('responsibleUser:id,name,active,deleted_at,institution_id');
        });
    }
}
