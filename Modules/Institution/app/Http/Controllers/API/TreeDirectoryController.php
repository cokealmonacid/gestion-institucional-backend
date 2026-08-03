<?php

namespace Modules\Institution\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Nodes\Http\Resources\NodeResource;
use Modules\Nodes\Models\Node;

class TreeDirectoryController extends BaseController
{
    private function activeNodeQuery(Request $request): Builder
    {
        $institutionId = $request->user()->institution_id;

        return $this->institutionNodeQuery($request)
            ->withExists(['children as has_children' => function ($query) use ($institutionId) {
                $query->where('institution_id', $institutionId);
                $query->where('active', true);
            }])
            ->where('active', true);
    }

    private function institutionNodeQuery(Request $request): Builder
    {
        return Node::where('institution_id', $request->user()->institution_id);
    }

    public function index(Request $request)
    {
        $nodes = $this->activeNodeQuery($request)
            ->whereNull('parent_id')
            ->orderBy('order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $this->sendResponse(NodeResource::collection($nodes), 'Tree directory retrieved successfully.');
    }

    public function show(Request $request, $node_id)
    {
        $node = $this->activeNodeQuery($request)
            ->find($node_id);

        if (!$node) {
            return $this->sendError('Node not found.', [], 404);
        }

        return $this->sendResponse(new NodeResource($node), 'Tree directory node retrieved successfully.');
    }

    public function children(Request $request, $node_id)
    {
        $parent = $this->activeNodeQuery($request)
            ->find($node_id);

        if (!$parent) {
            return $this->sendError('Node not found.', [], 404);
        }

        $nodes = $this->activeNodeQuery($request)
            ->where('parent_id', $parent->id)
            ->orderBy('order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $this->sendResponse(NodeResource::collection($nodes), 'Tree directory children retrieved successfully.');
    }

    public function store(Request $request, $node_id)
    {
        $parent = $this->activeNodeQuery($request)
            ->find($node_id);

        if (!$parent) {
            return $this->sendError('Parent node not found.', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'path' => ['nullable', 'string', 'max:255'],
            'order' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $siblingCount = $parent->children()->count();

        $node = Node::create([
            'name' => $request->name,
            // Pending tree policy: path/order are minimally derived until a canonical rule is defined.
            'path' => $request->path ?? trim($parent->path . '/' . $request->name, '/'),
            'depth' => $parent->depth + 1,
            'order' => $request->order ?? (string) ($siblingCount + 1),
            'active' => true,
            'institution_id' => $parent->institution_id,
            'parent_id' => $parent->id,
        ]);

        return $this->sendResponse($node, 'Tree directory node created successfully.');
    }

    public function destroy(Request $request, $node_id)
    {
        $node = $this->activeNodeQuery($request)
            ->find($node_id);

        if (!$node) {
            return $this->sendError('Node not found.', [], 404);
        }

        // Safer than physical deletion because nodes can have children/documents and the model has no SoftDeletes.
        $node->active = false;
        $node->save();
        $node->makeHidden('has_children');

        return $this->sendResponse($node, 'Tree directory node deactivated successfully.');
    }

    public function activate(Request $request, $node_id)
    {
        $node = $this->institutionNodeQuery($request)
            ->find($node_id);

        if (!$node) {
            return $this->sendError('Node not found.', [], 404);
        }

        $node->active = true;
        $node->save();

        return $this->sendResponse($node, 'Tree directory node activated successfully.');
    }
}
