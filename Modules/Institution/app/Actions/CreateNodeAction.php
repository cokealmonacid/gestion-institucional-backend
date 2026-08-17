<?php

namespace Modules\Institution\Actions;

use App\Enums\RoleType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Institution\Exceptions\NodeCreationException;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use Modules\Nodes\Support\NodeName;

class CreateNodeAction
{
    private const ROOT_SCOPE = 'R';

    private const MAX_DEPTH = 100;

    public function execute(User $actor, ?string $parentId, mixed $name): Node
    {
        $this->authorize($actor);

        if ($actor->institution_id === null) {
            throw new NodeCreationException('NODE_PARENT_NOT_FOUND', 'The selected parent is not available.', 404);
        }

        try {
            $nameData = NodeName::normalize($name);
        } catch (\InvalidArgumentException $exception) {
            throw new NodeCreationException(
                'NODE_NAME_INVALID',
                $exception->getMessage(),
                422,
                ['name' => [$exception->getMessage()]],
            );
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction(function () use ($actor, $parentId, $nameData) {
                    $institution = Institution::query()->lockForUpdate()->find($actor->institution_id);

                    if ($institution === null) {
                        throw new NodeCreationException('NODE_PARENT_NOT_FOUND', 'The selected parent is not available.', 404);
                    }

                    $parent = null;
                    if ($parentId !== null) {
                        $parent = Node::query()
                            ->where('institution_id', $institution->id)
                            ->where('active', true)
                            ->lockForUpdate()
                            ->find($parentId);

                        if ($parent === null) {
                            throw new NodeCreationException('NODE_PARENT_NOT_FOUND', 'The selected parent is not available.', 404);
                        }

                        if (! $parent->canContainChildren()) {
                            throw new NodeCreationException('NODE_PARENT_NOT_CONTAINER', 'The selected parent cannot contain nodes.', 422);
                        }
                    }

                    $depth = $parent === null ? 0 : $parent->depth + 1;
                    if ($depth > self::MAX_DEPTH) {
                        throw new NodeCreationException('NODE_MAX_DEPTH_EXCEEDED', 'The maximum node depth has been reached.', 422);
                    }

                    $parentScope = $parent === null ? self::ROOT_SCOPE : 'P:'.$parent->id;
                    $duplicate = Node::query()
                        ->where('institution_id', $institution->id)
                        ->where('parent_scope', $parentScope)
                        ->where('name_fingerprint', $nameData['fingerprint'])
                        ->first();

                    if ($duplicate !== null) {
                        if ($duplicate->normalized_name === $nameData['normalized']) {
                            throw $this->duplicateName();
                        }

                        throw new \RuntimeException('A normalized node-name fingerprint collision was detected.');
                    }

                    $nextOrder = ((int) Node::query()
                        ->where('institution_id', $institution->id)
                        ->where('parent_scope', $parentScope)
                        ->max('order')) + 1;

                    $node = new Node;
                    $node->id = $node->newUniqueId();
                    $node->fill([
                        'name' => $nameData['display'],
                        'normalized_name' => $nameData['normalized'],
                        'name_fingerprint' => $nameData['fingerprint'],
                        'parent_scope' => $parentScope,
                        'path' => $parent === null ? $node->id : $parent->path.'/'.$node->id,
                        'depth' => $depth,
                        'order' => $nextOrder,
                        'active' => true,
                        'institution_id' => $institution->id,
                        'parent_id' => $parent?->id,
                    ]);
                    $node->save();
                    $node->setAttribute('has_children', false);

                    return $node;
                }, 3);
            } catch (QueryException $exception) {
                $message = strtolower($exception->getMessage());

                if (str_contains($message, 'nodes_sibling_name_unique')
                    || str_contains($message, 'nodes.institution_id, nodes.parent_scope, nodes.name_fingerprint')) {
                    throw $this->duplicateName();
                }

                if ((str_contains($message, 'nodes_sibling_order_unique')
                    || str_contains($message, 'nodes.institution_id, nodes.parent_scope, nodes.order'))
                    && $attempt < 3) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new \RuntimeException('Unable to assign a node order after three attempts.');
    }

    private function duplicateName(): NodeCreationException
    {
        return new NodeCreationException(
            'NODE_NAME_DUPLICATE',
            'A node with this name already exists in the selected location.',
            409,
            ['name' => ['A node with this name already exists in the selected location.']],
        );
    }

    public function authorize(User $actor): void
    {
        if (! $actor->roles()->whereIn('type', [RoleType::Admin->value, RoleType::Editor->value])->exists()) {
            throw new NodeCreationException('NODE_CREATE_FORBIDDEN', 'You are not allowed to create nodes.', 403);
        }
    }
}
