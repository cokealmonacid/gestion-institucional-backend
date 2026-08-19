<?php

namespace Modules\Documents\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Documents\Http\Resources\DocumentVersionResource;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentDownload;
use Modules\Documents\Models\DocumentVersion;
use Modules\Documents\Services\DocumentLifecycleAccess;

class DocumentVersionsController extends BaseController
{
    private function institutionDocumentQuery(Request $request): Builder
    {
        return Document::where('institution_id', $request->user()->institution_id);
    }

    private function documentVersionQuery(Document $document): Builder
    {
        return DocumentVersion::where('document_id', $document->id)
            ->where('institution_id', $document->institution_id);
    }

    private function findDocument(Request $request, string $documentId): ?Document
    {
        return $this->institutionDocumentQuery($request)->find($documentId);
    }

    private function lockDocument(Document $document): ?Document
    {
        return Document::where('id', $document->id)
            ->where('institution_id', $document->institution_id)
            ->where('status', true)
            ->whereNotNull('node_id')
            ->lockForUpdate()
            ->first();
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

    public function index(Request $request, $document_id, DocumentLifecycleAccess $access)
    {
        $document = $access->findDocument($request->user(), $document_id);

        if (! $document) {
            return ApiResponse::error('DOCUMENT_NOT_AVAILABLE', 'The document is not available.', 404);
        }

        $versions = $this->documentVersionQuery($document)
            ->where('active', true)
            ->with('author:id,name')
            ->orderByDesc('version_number')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(
            DocumentVersionResource::collection($versions)->resolve($request),
            'Document versions retrieved successfully.',
        );
    }

    public function store(Request $request, $document_id, DocumentLifecycleAccess $access)
    {
        $document = $access->findDocument($request->user(), $document_id);

        if (! $document) {
            return ApiResponse::error('DOCUMENT_NOT_AVAILABLE', 'The document is not available.', 404);
        }

        if (! $access->canMutate($request->user())) {
            return ApiResponse::error('DOCUMENT_VERSION_MUTATION_FORBIDDEN', 'You are not allowed to modify document versions.', 403);
        }

        $additionalFields = array_values(array_diff(array_keys($request->all()), ['file']));
        if ($additionalFields !== []) {
            return ApiResponse::error(
                'VALIDATION_FAILED',
                'The document version request is invalid.',
                422,
                collect($additionalFields)->mapWithKeys(fn ($field) => [$field => ['The '.$field.' field is prohibited.']])->all(),
            );
        }

        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,jpeg,png',
                'min:1',
                'max:25600',
            ],
            'comment' => ['prohibited'],
            'version_number' => ['prohibited'],
            'url' => ['prohibited'],
            'filename' => ['prohibited'],
            'mime_type' => ['prohibited'],
            'file_size' => ['prohibited'],
            'document_id' => ['prohibited'],
            'institution_id' => ['prohibited'],
            'node_id' => ['prohibited'],
            'author_id' => ['prohibited'],
            'is_current' => ['prohibited'],
            'active' => ['prohibited'],
            'status' => ['prohibited'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('VALIDATION_FAILED', 'The document version request is invalid.', 422, $validator->errors()->toArray());
        }

        $file = $request->file('file');
        $versionId = (string) Str::uuid();
        $filename = $this->safeFilename($file->getClientOriginalName());
        $extension = $file->getClientOriginalExtension();
        $storedFilename = $versionId.($extension ? '.'.strtolower($extension) : '');
        $path = 'institutions/'.$document->institution_id
            .'/documents/'.$document->id
            .'/versions/'.$versionId
            .'/'.$storedFilename;

        $disk = config('filesystems.default');
        try {
            $stored = $file->storeAs(dirname($path), basename($path), $disk);
        } catch (\Throwable) {
            return ApiResponse::error('DOCUMENT_STORAGE_FAILED', 'The document file could not be stored.', 500);
        }

        if (! $stored) {
            return ApiResponse::error('DOCUMENT_STORAGE_FAILED', 'The document file could not be stored.', 500);
        }

        try {
            $storedFileIsValid = Storage::disk($disk)->exists($stored)
                && Storage::disk($disk)->size($stored) > 0;
        } catch (\Throwable) {
            $this->deleteStoredFile($disk, $stored);

            return ApiResponse::error('DOCUMENT_STORAGE_FAILED', 'The stored document file could not be verified.', 500);
        }

        if (! $storedFileIsValid) {
            $this->deleteStoredFile($disk, $stored);

            return ApiResponse::error('DOCUMENT_STORAGE_FAILED', 'The stored document file could not be verified.', 500);
        }

        try {
            $version = DB::transaction(function () use ($request, $document, $file, $versionId, $filename, $stored) {
                $lockedDocument = $this->lockDocument($document);
                if (! $lockedDocument) {
                    return null;
                }
                $nextVersionNumber = ((int) $this->documentVersionQuery($lockedDocument)->max('version_number')) + 1;

                $this->documentVersionQuery($lockedDocument)->update([
                    'is_current' => false,
                ]);

                $version = new DocumentVersion;
                $version->id = $versionId;
                $version->fill([
                    'version_number' => $nextVersionNumber,
                    // The legacy column stores an internal storage path/key, not necessarily a public URL.
                    'url' => $stored,
                    'filename' => $filename,
                    'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'comment' => null,
                    'author_id' => $request->user()->id,
                    'document_id' => $lockedDocument->id,
                    'institution_id' => $lockedDocument->institution_id,
                    'node_id' => $lockedDocument->node_id,
                    'active' => true,
                    'is_current' => true,
                ]);
                $version->save();

                return $version;
            });
        } catch (\Throwable) {
            $this->deleteStoredFile($disk, $stored);

            return ApiResponse::error('DOCUMENT_VERSION_CREATION_FAILED', 'The document version could not be created.', 500);
        }

        if (! $version) {
            $this->deleteStoredFile($disk, $stored);

            return ApiResponse::error('DOCUMENT_NOT_AVAILABLE', 'The document is not available.', 404);
        }

        $version->load('author:id,name');

        return response()->json([
            'success' => true,
            'data' => (new DocumentVersionResource($version))->resolve($request),
            'message' => 'Document version created successfully.',
        ], 201, [
            'Location' => url("/api/v1/documents/{$document->id}/versions/{$version->id}"),
        ]);
    }

    public function show(Request $request, $document_id, $version_id, DocumentLifecycleAccess $access)
    {
        $document = $access->findDocument($request->user(), $document_id);

        if (! $document) {
            return ApiResponse::error('DOCUMENT_NOT_AVAILABLE', 'The document is not available.', 404);
        }

        $version = $access->findVersion($document, $version_id);

        if (! $version) {
            return ApiResponse::error('DOCUMENT_VERSION_NOT_AVAILABLE', 'The document version is not available.', 404);
        }

        return ApiResponse::success(
            (new DocumentVersionResource($version))->resolve($request),
            'Document version retrieved successfully.',
        );
    }

    public function destroy(Request $request, $document_id, $version_id)
    {
        $document = $this->findDocument($request, $document_id);

        if (! $document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $version = $this->documentVersionQuery($document)->find($version_id);

        if (! $version) {
            return $this->sendError('Document version not found.', [], 404);
        }

        $version->active = false;
        $version->is_current = false;
        $version->save();

        return $this->sendResponse($version, 'Document version deactivated successfully.');
    }

    public function activate(Request $request, $document_id, $version_id)
    {
        $document = $this->findDocument($request, $document_id);

        if (! $document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $version = $this->documentVersionQuery($document)->find($version_id);

        if (! $version) {
            return $this->sendError('Document version not found.', [], 404);
        }

        $version->active = true;
        $version->save();

        return $this->sendResponse($version, 'Document version activated successfully.');
    }

    public function current(Request $request, $document_id, $version_id, DocumentLifecycleAccess $access)
    {
        $document = $access->findDocument($request->user(), $document_id);

        if (! $document) {
            return ApiResponse::error('DOCUMENT_NOT_AVAILABLE', 'The document is not available.', 404);
        }

        if (! $access->canMutate($request->user())) {
            return ApiResponse::error('DOCUMENT_VERSION_MUTATION_FORBIDDEN', 'You are not allowed to modify document versions.', 403);
        }

        $version = $this->documentVersionQuery($document)
            ->where('active', true)
            ->find($version_id);

        if (! $version) {
            return ApiResponse::error('DOCUMENT_VERSION_NOT_AVAILABLE', 'The document version is not available.', 404);
        }

        $version = DB::transaction(function () use ($document, $version) {
            $lockedDocument = $this->lockDocument($document);
            if (! $lockedDocument) {
                return null;
            }
            $lockedVersion = $this->documentVersionQuery($lockedDocument)
                ->where('active', true)
                ->lockForUpdate()
                ->find($version->id);

            if (! $lockedVersion) {
                return null;
            }

            $this->documentVersionQuery($lockedDocument)->update([
                'is_current' => false,
            ]);

            $lockedVersion->is_current = true;
            $lockedVersion->save();

            return $lockedVersion;
        });

        if (! $version) {
            return ApiResponse::error('DOCUMENT_VERSION_NOT_AVAILABLE', 'The document version is not available.', 404);
        }

        $version->load('author:id,name');

        return ApiResponse::success(
            (new DocumentVersionResource($version))->resolve($request),
            'Document version marked as current successfully.',
        );
    }

    public function download(Request $request, $document_id, $version_id, DocumentLifecycleAccess $access)
    {
        $document = $access->findDocument($request->user(), $document_id);

        if (! $document) {
            return ApiResponse::error('DOCUMENT_NOT_AVAILABLE', 'The document is not available.', 404);
        }

        $version = $access->findVersion($document, $version_id);

        if (! $version) {
            return ApiResponse::error('DOCUMENT_VERSION_NOT_AVAILABLE', 'The document version is not available.', 404);
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

    private function deleteStoredFile(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable) {
            // Best-effort compensation; an external storage failure may leave an orphaned file.
        }
    }
}
