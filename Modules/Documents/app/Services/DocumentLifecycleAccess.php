<?php

namespace Modules\Documents\Services;

use App\Enums\RoleType;
use App\Models\User;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentVersion;
use Modules\Nodes\Models\Node;

class DocumentLifecycleAccess
{
    public function findDocument(User $actor, string $documentId): ?Document
    {
        if ($actor->institution_id === null) {
            return null;
        }

        $document = Document::query()
            ->where('institution_id', $actor->institution_id)
            ->where('status', true)
            ->whereNotNull('node_id')
            ->with(['author:id,name', 'node:id,name,path,institution_id,active'])
            ->find($documentId);

        if (! $document || ! $document->node || ! $this->nodePathIsAccessible($document->node)) {
            return null;
        }

        return $document;
    }

    public function findVersion(Document $document, string $versionId): ?DocumentVersion
    {
        return $document->versions()
            ->where('institution_id', $document->institution_id)
            ->where('active', true)
            ->with('author:id,name')
            ->find($versionId);
    }

    public function canMutate(User $actor): bool
    {
        return $actor->roles()
            ->whereIn('type', [RoleType::Admin->value, RoleType::Editor->value])
            ->exists();
    }

    private function nodePathIsAccessible(Node $node): bool
    {
        $pathIds = array_values(array_filter(explode('/', $node->path)));

        return $pathIds !== [] && Node::query()
            ->where('institution_id', $node->institution_id)
            ->whereIn('id', $pathIds)
            ->where('active', true)
            ->count() === count($pathIds);
    }
}
