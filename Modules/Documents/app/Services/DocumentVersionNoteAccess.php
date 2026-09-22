<?php

namespace Modules\Documents\Services;

use App\Models\User;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentVersion;

class DocumentVersionNoteAccess
{
    public function findDocument(User $actor, string $documentId): ?Document
    {
        if ($actor->institution_id === null) {
            return null;
        }

        return Document::query()
            ->where('institution_id', $actor->institution_id)
            ->find($documentId);
    }

    public function findVersion(Document $document, string $versionId): ?DocumentVersion
    {
        return DocumentVersion::query()
            ->where('document_id', $document->id)
            ->where('institution_id', $document->institution_id)
            ->find($versionId);
    }
}
