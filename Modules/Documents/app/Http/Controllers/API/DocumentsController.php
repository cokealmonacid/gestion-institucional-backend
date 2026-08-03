<?php

namespace Modules\Documents\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Modules\Documents\Http\Resources\DocumentResource;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentDownload;
use Modules\Documents\Models\DocumentVersion;
use Modules\Nodes\Models\Node;

class DocumentsController extends BaseController
{
    private function institutionDocumentQuery(Request $request): Builder
    {
        return Document::where('institution_id', $request->user()->institution_id);
    }

    private function activeNodeQuery(Request $request): Builder
    {
        return Node::where('institution_id', $request->user()->institution_id)
            ->where('active', true);
    }

    private function downloadVersion(Request $request, Document $document, DocumentVersion $version)
    {
        $disk = Storage::disk(config('filesystems.default'));

        if (!$version->url || !$disk->exists($version->url)) {
            return $this->sendError('Document file not found.', [], 404);
        }

        DocumentDownload::create([
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'user_id' => $request->user()->id,
        ]);

        return $disk->download($version->url, $version->filename, array_filter([
            'Content-Type' => $version->mime_type,
        ]));
    }

    public function indexByNode(Request $request, $node_id)
    {
        $node = $this->activeNodeQuery($request)->find($node_id);

        if (!$node) {
            return $this->sendError('Node not found.', [], 404);
        }

        $documents = $this->institutionDocumentQuery($request)
            ->where('node_id', $node->id)
            ->where('status', true)
            ->orderBy('created_at', 'desc')
            ->orderBy('id')
            ->get();

        return $this->sendResponse(DocumentResource::collection($documents), 'Documents retrieved successfully.');
    }

    public function store(Request $request, $node_id)
    {
        $node = $this->activeNodeQuery($request)->find($node_id);

        if (!$node) {
            return $this->sendError('Node not found.', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'responsible_unit' => ['nullable', 'string', 'max:255'],
            'institution_id' => ['prohibited'],
            'author_id' => ['prohibited'],
            'node_id' => ['prohibited'],
            'status' => ['prohibited'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $document = Document::create([
            'name' => $request->name,
            'description' => $request->description,
            'category' => $request->category,
            'responsible_unit' => $request->responsible_unit,
            'status' => true,
            'author_id' => $request->user()->id,
            'institution_id' => $request->user()->institution_id,
            'node_id' => $node->id,
        ]);

        return $this->sendResponse($document, 'Document created successfully.');
    }

    public function show(Request $request, $document_id)
    {
        $document = $this->institutionDocumentQuery($request)->find($document_id);

        if (!$document) {
            return $this->sendError('Document not found.', [], 404);
        }

        return $this->sendResponse($document, 'Document retrieved successfully.');
    }

    public function update(Request $request, $document_id)
    {
        $document = $this->institutionDocumentQuery($request)->find($document_id);

        if (!$document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'responsible_unit' => ['sometimes', 'nullable', 'string', 'max:255'],
            'institution_id' => ['prohibited'],
            'author_id' => ['prohibited'],
            'node_id' => ['prohibited'],
            'status' => ['prohibited'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $document->fill($request->only([
            'name',
            'description',
            'category',
            'responsible_unit',
        ]));
        $document->save();

        return $this->sendResponse($document, 'Document updated successfully.');
    }

    public function destroy(Request $request, $document_id)
    {
        $document = $this->institutionDocumentQuery($request)->find($document_id);

        if (!$document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $document->status = false;
        $document->save();

        return $this->sendResponse($document, 'Document deactivated successfully.');
    }

    public function activate(Request $request, $document_id)
    {
        $document = $this->institutionDocumentQuery($request)->find($document_id);

        if (!$document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $document->status = true;
        $document->save();

        return $this->sendResponse($document, 'Document activated successfully.');
    }

    public function download(Request $request, $document_id)
    {
        $document = $this->institutionDocumentQuery($request)
            ->where('status', true)
            ->find($document_id);

        if (!$document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $version = $document->versions()
            ->where('institution_id', $document->institution_id)
            ->where('active', true)
            ->where('is_current', true)
            ->first();

        if (!$version) {
            return $this->sendError('Current document version not found.', [], 404);
        }

        return $this->downloadVersion($request, $document, $version);
    }
}
