<?php

namespace Modules\Institution\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Nodes\Models\Node;

class TreeDirectoryController extends BaseController
{
    public function index(Request $request)
    {
        $institutionId = $request->user()->institution_id;

        $nodes = Node::withExists(['children as has_children' => function ($query) use ($institutionId) {
            $query->where('institution_id', $institutionId);
            $query->where('active', true);
        }])
            ->where('institution_id', $institutionId)
            ->whereNull('parent_id')
            ->where('active', true)
            ->orderBy('order')
            ->get();

        return $this->sendResponse($nodes, 'Tree directory retrieved successfully.');
    }

    public function show(Request $request, $node_id)
    {
        $institutionId = $request->user()->institution_id;

        $node = Node::withExists(['children as has_children' => function ($query) use ($institutionId) {
            $query->where('institution_id', $institutionId);
            $query->where('active', true);
        }])
            ->where('institution_id', $institutionId)
            ->where('active', true)
            ->find($node_id);

        if (!$node) {
            return $this->sendError('Node not found.', [], 404);
        }

        return $this->sendResponse($node, 'Tree directory node retrieved successfully.');
    }

    public function children(Request $request, $node_id)
    {
        $institutionId = $request->user()->institution_id;

        $parent = Node::where('institution_id', $institutionId)
            ->where('active', true)
            ->find($node_id);

        if (!$parent) {
            return $this->sendError('Node not found.', [], 404);
        }

        $nodes = Node::withExists(['children as has_children' => function ($query) use ($institutionId) {
            $query->where('institution_id', $institutionId);
            $query->where('active', true);
        }])
            ->where('institution_id', $institutionId)
            ->where('parent_id', $parent->id)
            ->where('active', true)
            ->orderBy('order')
            ->get();

        return $this->sendResponse($nodes, 'Tree directory children retrieved successfully.');
    }

    public function store(Request $request, $node_id)
    {
        $institutionId = $request->user()->institution_id;

        $parent = Node::where('institution_id', $institutionId)
            ->where('active', true)
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

        $siblingCount = Node::where('parent_id', $parent->id)
            ->where('institution_id', $institutionId)
            ->count();

        $node = Node::create([
            'name' => $request->name,
            // Pending tree policy: path/order are minimally derived until a canonical rule is defined.
            'path' => $request->path ?? trim($parent->path . '/' . $request->name, '/'),
            'depth' => $parent->depth + 1,
            'order' => $request->order ?? (string) ($siblingCount + 1),
            'active' => true,
            'institution_id' => $institutionId,
            'parent_id' => $parent->id,
        ]);

        return $this->sendResponse($node, 'Tree directory node created successfully.');
    }

    public function destroy(Request $request, $node_id)
    {
        $institutionId = $request->user()->institution_id;

        $node = Node::where('institution_id', $institutionId)
            ->where('active', true)
            ->find($node_id);

        if (!$node) {
            return $this->sendError('Node not found.', [], 404);
        }

        // Safer than physical deletion because nodes can have children/documents and the model has no SoftDeletes.
        $node->active = false;
        $node->save();

        return $this->sendResponse($node, 'Tree directory node deactivated successfully.');
    }
}
