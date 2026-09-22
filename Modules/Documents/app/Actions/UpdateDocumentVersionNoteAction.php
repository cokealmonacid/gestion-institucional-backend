<?php

namespace Modules\Documents\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Documents\Enums\DocumentEventType;
use Modules\Documents\Exceptions\DocumentVersionNoteException;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentVersion;
use Modules\Documents\Models\DocumentVersionCommentHistory;
use Modules\Documents\Services\DocumentEventRecorder;

class UpdateDocumentVersionNoteAction
{
    /** @return array{version: DocumentVersion, history: DocumentVersionCommentHistory} */
    public function execute(
        User $actor,
        string $documentId,
        string $versionId,
        ?string $note,
        DocumentEventRecorder $events,
    ): array {
        return DB::transaction(function () use ($actor, $documentId, $versionId, $note, $events): array {
            $document = Document::query()
                ->where('institution_id', $actor->institution_id)
                ->lockForUpdate()
                ->find($documentId);

            if (! $document) {
                throw new DocumentVersionNoteException('DOCUMENT_NOT_AVAILABLE', 'El documento no está disponible.', 404);
            }

            $version = DocumentVersion::query()
                ->where('document_id', $document->id)
                ->where('institution_id', $document->institution_id)
                ->lockForUpdate()
                ->find($versionId);

            if (! $version) {
                throw new DocumentVersionNoteException('DOCUMENT_VERSION_NOT_AVAILABLE', 'La versión no está disponible.', 404);
            }

            if (! $document->status || ! $version->active) {
                throw new DocumentVersionNoteException(
                    'DOCUMENT_VERSION_NOTE_READ_ONLY',
                    'La nota está en modo de solo lectura.',
                    409,
                );
            }

            if ($version->comment === $note) {
                throw new DocumentVersionNoteException(
                    'DOCUMENT_VERSION_NOTE_UNCHANGED',
                    'La nota debe ser diferente de la nota vigente.',
                    409,
                );
            }

            $history = DocumentVersionCommentHistory::create([
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'user_id' => $actor->id,
                'actor_name' => $actor->name,
                'previous_comment' => $version->comment,
                'new_comment' => $note,
            ]);

            $version->comment = $note;
            $version->save();

            $events->record(
                $document,
                $note === null ? DocumentEventType::VersionNoteCleared : DocumentEventType::VersionNoteUpdated,
                $actor,
                ['version' => DocumentEventRecorder::versionSnapshot($version)],
                'document_version_note_history',
                $history->id,
                $version,
                $history->created_at,
            );

            return [
                'version' => $version->load('latestCommentHistory.user'),
                'history' => $history->load('user'),
            ];
        });
    }
}
