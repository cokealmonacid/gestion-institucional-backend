<?php

namespace Modules\Documents\Actions;

use App\Models\User;
use Modules\Documents\Exceptions\DocumentCreationException;
use Modules\Documents\Models\Document;
use Modules\Nodes\Models\Node;

class CreateDocumentAction
{
    /** @param array{name: string, description?: ?string, category?: ?string, responsible_unit?: ?string} $attributes */
    public function execute(User $actor, string $nodeId, array $attributes): Document
    {
        if ($actor->institution_id === null) {
            throw $this->locationNotFound();
        }

        $node = Node::query()
            ->where('institution_id', $actor->institution_id)
            ->where('active', true)
            ->find($nodeId);

        if ($node === null || ! $this->ancestorsAreAccessible($node)) {
            throw $this->locationNotFound();
        }

        return Document::create([
            ...$attributes,
            'status' => true,
            'author_id' => $actor->id,
            'institution_id' => $actor->institution_id,
            'node_id' => $node->id,
        ]);
    }

    private function ancestorsAreAccessible(Node $node): bool
    {
        $pathIds = explode('/', $node->path);

        return Node::query()
            ->where('institution_id', $node->institution_id)
            ->whereIn('id', $pathIds)
            ->where('active', true)
            ->count() === count($pathIds);
    }

    private function locationNotFound(): DocumentCreationException
    {
        return new DocumentCreationException(
            'DOCUMENT_LOCATION_NOT_FOUND',
            'The selected document location is not available.',
            404,
        );
    }
}
