<?php

namespace Modules\Documents\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentVersion;

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

    private function lockDocument(Document $document): Document
    {
        return Document::where('id', $document->id)
            ->where('institution_id', $document->institution_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function index(Request $request, $document_id)
    {
        $document = $this->findDocument($request, $document_id);

        if (!$document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $versions = $this->documentVersionQuery($document)
            ->when(!$request->boolean('include_inactive'), function (Builder $query) {
                $query->where('active', true);
            })
            ->orderByDesc('version_number')
            ->orderByDesc('created_at')
            ->get();

        return $this->sendResponse($versions, 'Document versions retrieved successfully.');
    }

    public function store(Request $request, $document_id)
    {
        $document = $this->findDocument($request, $document_id);

        if (!$document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'file' => [
                'required',
                'file',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,jpeg,png',
                'max:10240',
            ],
            'comment' => ['nullable', 'string'],
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
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $file = $request->file('file');
        $versionId = (string) Str::uuid();
        $filename = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $storedFilename = Str::slug(pathinfo($filename, PATHINFO_FILENAME));
        $storedFilename = ($storedFilename !== '' ? $storedFilename : 'document').'-'.$versionId;
        $storedFilename .= $extension ? '.'.strtolower($extension) : '';
        $path = 'institutions/'.$document->institution_id
            .'/documents/'.$document->id
            .'/versions/'.$versionId
            .'/'.$storedFilename;

        $disk = config('filesystems.default');
        $stored = $file->storeAs(dirname($path), basename($path), $disk);

        if (!$stored) {
            return $this->sendError('File could not be stored.', [], 500);
        }

        if (!Storage::disk($disk)->exists($stored) || Storage::disk($disk)->size($stored) <= 0) {
            Storage::disk($disk)->delete($stored);

            return $this->sendError('Stored file is empty or could not be verified.', [], 500);
        }

        try {
            $version = DB::transaction(function () use ($request, $document, $file, $versionId, $filename, $stored) {
                $lockedDocument = $this->lockDocument($document);
                $nextVersionNumber = ((int) $this->documentVersionQuery($lockedDocument)->max('version_number')) + 1;

                $this->documentVersionQuery($lockedDocument)->update(['is_current' => false]);

                $version = new DocumentVersion();
                $version->id = $versionId;
                $version->fill([
                    'version_number' => $nextVersionNumber,
                    // The legacy column stores an internal storage path/key, not necessarily a public URL.
                    'url' => $stored,
                    'filename' => $filename,
                    'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'comment' => $request->comment,
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
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($stored);

            throw $e;
        }

        return $this->sendResponse($version, 'Document version created successfully.');
    }

    public function show(Request $request, $document_id, $version_id)
    {
        $document = $this->findDocument($request, $document_id);

        if (!$document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $version = $this->documentVersionQuery($document)->find($version_id);

        if (!$version) {
            return $this->sendError('Document version not found.', [], 404);
        }

        return $this->sendResponse($version, 'Document version retrieved successfully.');
    }

    public function destroy(Request $request, $document_id, $version_id)
    {
        $document = $this->findDocument($request, $document_id);

        if (!$document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $version = $this->documentVersionQuery($document)->find($version_id);

        if (!$version) {
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

        if (!$document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $version = $this->documentVersionQuery($document)->find($version_id);

        if (!$version) {
            return $this->sendError('Document version not found.', [], 404);
        }

        $version->active = true;
        $version->save();

        return $this->sendResponse($version, 'Document version activated successfully.');
    }

    public function current(Request $request, $document_id, $version_id)
    {
        $document = $this->findDocument($request, $document_id);

        if (!$document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $version = $this->documentVersionQuery($document)
            ->where('active', true)
            ->find($version_id);

        if (!$version) {
            return $this->sendError('Active document version not found.', [], 404);
        }

        $version = DB::transaction(function () use ($document, $version) {
            $lockedDocument = $this->lockDocument($document);
            $lockedVersion = $this->documentVersionQuery($lockedDocument)
                ->where('active', true)
                ->lockForUpdate()
                ->find($version->id);

            if (!$lockedVersion) {
                return null;
            }

            $this->documentVersionQuery($lockedDocument)->update(['is_current' => false]);

            $lockedVersion->is_current = true;
            $lockedVersion->save();

            return $lockedVersion;
        });

        if (!$version) {
            return $this->sendError('Active document version not found.', [], 404);
        }

        return $this->sendResponse($version, 'Document version marked as current successfully.');
    }
}
