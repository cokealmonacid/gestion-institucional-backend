<?php

namespace Modules\Documents\Actions;

use App\Models\User;
use Modules\Documents\Models\Document;
use Modules\Documents\Services\DocumentEventRecorder;

class RenameDocumentAction
{
    public function __construct(private readonly UpdateDocumentAction $update) {}

    public function execute(User $actor, string $documentId, string $name, DocumentEventRecorder $events): Document
    {
        return $this->update->execute($actor, $documentId, ['name' => $name], $events);
    }
}
