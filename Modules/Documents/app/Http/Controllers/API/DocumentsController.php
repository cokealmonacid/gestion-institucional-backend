<?php

namespace Modules\Documents\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Modules\Documents\Actions\CreateDocumentAction;
use Modules\Documents\Exceptions\DocumentCreationException;
use Modules\Documents\Http\Requests\CreateDocumentRequest;
use Modules\Documents\Http\Resources\DocumentLifecycleResource;
use Modules\Documents\Http\Resources\DocumentResource;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentDownload;
use Modules\Documents\Models\DocumentVersion;
use Modules\Documents\Services\DocumentLifecycleAccess;
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
        try {
            $disk = Storage::disk(config('filesystems.default'));
            $exists = $version->url && $disk->exists($version->url);
        } catch (\Throwable) {
            return ApiResponse::error('DOCUMENT_STORAGE_FAILED', 'The document storage service is unavailable.', 500);
        }

        if (! $exists) {
            return ApiResponse::error('DOCUMENT_FILE_NOT_AVAILABLE', 'The document file is not available.', 404);
        }

        try {
            $response = $disk->download($version->url, $this->safeFilename($version->filename), array_filter([
                'Content-Type' => $version->mime_type,
            ]));
            DocumentDownload::create([
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'user_id' => $request->user()->id,
            ]);

            return $response;
        } catch (\Throwable) {
            return ApiResponse::error('DOCUMENT_STORAGE_FAILED', 'The document download could not be emitted.', 500);
        }
    }

    public function indexByNode(Request $request, $node_id)
    {
        $node = $this->activeNodeQuery($request)->find($node_id);

        if (! $node) {
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

    public function store(CreateDocumentRequest $request, $node_id, CreateDocumentAction $action)
    {
        try {
            $document = $action->execute($request->user(), $node_id, $request->validated());
        } catch (DocumentCreationException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->fields,
            );
        }

        return response()->json([
            'success' => true,
            'data' => (new DocumentResource($document))->resolve($request),
            'message' => 'Document created successfully.',
        ], 201, [
            'Location' => url("/api/v1/documents/{$document->id}"),
        ]);
    }

    public function show(Request $request, $document_id, DocumentLifecycleAccess $access)
    {
        $document = $access->findDocument($request->user(), $document_id);

        if (! $document) {
            return ApiResponse::error('DOCUMENT_NOT_AVAILABLE', 'The document is not available.', 404);
        }

        $document->load(['versions' => function ($query) {
            $query->where('active', true)
                ->with('author:id,name')
                ->orderByDesc('version_number')
                ->orderBy('id');
        }]);

        return ApiResponse::success(
            (new DocumentLifecycleResource($document))->resolve($request),
            'Document lifecycle detail retrieved successfully.',
        );
    }

    public function update(Request $request, $document_id)
    {
        $document = $this->institutionDocumentQuery($request)->find($document_id);

        if (! $document) {
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

        if (! $document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $document->status = false;
        $document->save();

        return $this->sendResponse($document, 'Document deactivated successfully.');
    }

    public function activate(Request $request, $document_id)
    {
        $document = $this->institutionDocumentQuery($request)->find($document_id);

        if (! $document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $document->status = true;
        $document->save();

        return $this->sendResponse($document, 'Document activated successfully.');
    }

    public function download(Request $request, $document_id, DocumentLifecycleAccess $access)
    {
        $document = $access->findDocument($request->user(), $document_id);

        if (! $document) {
            return ApiResponse::error('DOCUMENT_NOT_AVAILABLE', 'The document is not available.', 404);
        }

        $version = $document->versions()
            ->where('institution_id', $document->institution_id)
            ->where('active', true)
            ->where('is_current', true)
            ->first();

        if (! $version) {
            return ApiResponse::error('DOCUMENT_VERSION_NOT_AVAILABLE', 'The current document version is not available.', 404);
        }

        return $this->downloadVersion($request, $document, $version);
    }

    private function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $filename));

        if ($filename === '') {
            return 'document';
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $suffix = $extension !== '' ? '.'.$extension : '';

        return mb_substr($basename, 0, max(1, 240 - mb_strlen($suffix))).$suffix;
    }
}
