<?php

namespace Modules\Institution\Actions;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Institution\Exceptions\NodeRenameException;
use Modules\Nodes\Models\Node;
use Modules\Nodes\Support\NodeName;

class RenameNodeAction
{
    public function execute(User $actor, string $nodeId, mixed $name): Node
    {
        $nameData = NodeName::normalize($name);

        try {
            return DB::transaction(function () use ($actor, $nodeId, $nameData): Node {
                $node = Node::query()
                    ->where('institution_id', $actor->institution_id)
                    ->lockForUpdate()
                    ->find($nodeId);

                if ($node === null || ! $this->pathIsActive($node)) {
                    throw $this->notAvailable();
                }

                $currentDisplay = NodeName::normalize($node->name)['display'];
                if ($currentDisplay === $nameData['display']) {
                    return $node;
                }

                $duplicate = Node::query()
                    ->where('institution_id', $node->institution_id)
                    ->where('parent_scope', $node->parent_scope)
                    ->where('name_fingerprint', $nameData['fingerprint'])
                    ->where('id', '!=', $node->id)
                    ->first();

                if ($duplicate !== null) {
                    if ($duplicate->normalized_name === $nameData['normalized']) {
                        throw $this->duplicateName();
                    }

                    throw new \RuntimeException('A normalized node-name fingerprint collision was detected.');
                }

                $node->name = $nameData['display'];
                $node->save();

                return $node;
            }, 3);
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());
            if (str_contains($message, 'nodes_sibling_name_unique')
                || str_contains($message, 'nodes.institution_id, nodes.parent_scope, nodes.name_fingerprint')) {
                throw $this->duplicateName();
            }

            throw $exception;
        }
    }

    private function pathIsActive(Node $node): bool
    {
        $pathIds = explode('/', $node->path);

        return Node::query()
            ->where('institution_id', $node->institution_id)
            ->whereIn('id', $pathIds)
            ->where('active', true)
            ->lockForUpdate()
            ->count() === count($pathIds);
    }

    private function notAvailable(): NodeRenameException
    {
        return new NodeRenameException('NODE_NOT_AVAILABLE', 'The node is not available.', 404);
    }

    private function duplicateName(): NodeRenameException
    {
        $message = 'A node with this name already exists in the selected location.';

        return new NodeRenameException('NODE_NAME_DUPLICATE', $message, 409, ['name' => [$message]]);
    }
}
