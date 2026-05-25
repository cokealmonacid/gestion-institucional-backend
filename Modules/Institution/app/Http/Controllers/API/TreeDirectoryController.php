<?php

namespace Modules\Institution\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Nodes\Models\Node;

class TreeDirectoryController extends BaseController
{
    private function baseNodeQuery(Request $request): Builder
    {
        $institutionId = $request->user()->institution_id;

        return Node::withExists(['children as has_children' => function ($query) use ($institutionId) {
            $query->where('institution_id', $institutionId);
            $query->where('active', true);
        }])
            ->where('institution_id', $institutionId)
            ->where('active', true);
    }

    public function index(Request $request)
    {
        $nodes = $this->baseNodeQuery($request)
            ->whereNull('parent_id')
            ->orderBy('order')
            ->get();

        return $this->sendResponse($nodes, 'Tree directory retrieved successfully.');
    }

    public function show(Request $request, $node_id)
    {
        $node = $this->baseNodeQuery($request)
            ->find($node_id);

        if (!$node) {
            return $this->sendError('Node not found.', [], 404);
        }

        return $this->sendResponse($node, 'Tree directory node retrieved successfully.');
    }

    public function children(Request $request, $node_id)
    {
        $parent = $this->baseNodeQuery($request)
            ->find($node_id);

        if (!$parent) {
            return $this->sendError('Node not found.', [], 404);
        }

        $nodes = $this->baseNodeQuery($request)
            ->where('parent_id', $parent->id)
            ->orderBy('order')
            ->get();

        return $this->sendResponse($nodes, 'Tree directory children retrieved successfully.');
    }

    public function store(Request $request, $node_id)
    {
        $parent = $this->baseNodeQuery($request)
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
        $node = $this->baseNodeQuery($request)
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
}
