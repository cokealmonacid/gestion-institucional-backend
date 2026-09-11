<?php

namespace Modules\Documents\Http\Controllers\API;

use App\Enums\InstitutionAbility;
use App\Http\Controllers\BaseController;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Documents\Actions\CreateDocumentAction;
use Modules\Documents\Actions\UpdateDocumentResponsibilityAction;
use Modules\Documents\Exceptions\DocumentCreationException;
use Modules\Documents\Exceptions\DocumentResponsibilityException;
use Modules\Documents\Http\Requests\CreateDocumentRequest;
use Modules\Documents\Http\Resources\DocumentLifecycleResource;
use Modules\Documents\Http\Resources\DocumentResource;
use Modules\Documents\Http\Resources\InstitutionDocumentResource;
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

    public function index(Request $request, DocumentLifecycleAccess $access)
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in([true, false, 1, 0, '1', '0', 'true', 'false'])],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        $documents = $this->institutionDocumentQuery($request)
            ->when(
                ! $request->has('status') || $request->boolean('status'),
                fn (Builder $query) => $query->where('status', true),
            )
            ->with('author:id,name')
            ->with('currentActiveVersion:id,document_id,url,active,is_current')
            ->withCount(['versions as active_versions_count' => fn ($query) => $query->where('active', true)])
            ->orderBy('created_at', 'desc')
            ->orderBy('id')
            ->paginate($request->input('per_page', 15));

        $request->attributes->set('document_lifecycle_can_mutate', $access->canMutate($request->user()));

        $documents->getCollection()->transform(
            fn (Document $document) => (new InstitutionDocumentResource($document))->resolve($request)
        );

        return $this->sendResponse($documents, 'Documents retrieved successfully.');
    }

    public function indexByNode(Request $request, $node_id, DocumentLifecycleAccess $access)
    {
        $node = $this->activeNodeQuery($request)->find($node_id);

        if (! $node) {
            return $this->sendError('Node not found.', [], 404);
        }

        $documents = $this->institutionDocumentQuery($request)
            ->where('node_id', $node->id)
            ->where('status', true)
            ->with('currentActiveVersion:id,document_id,url,active,is_current')
            ->withCount(['versions as active_versions_count' => fn ($query) => $query->where('active', true)])
            ->orderBy('created_at', 'desc')
            ->orderBy('id')
            ->get();

        $request->attributes->set('document_lifecycle_can_mutate', $access->canMutate($request->user()));

        return $this->sendResponse(DocumentResource::collection($documents), 'Documents retrieved successfully.');
    }

    public function store(
        CreateDocumentRequest $request,
        $node_id,
        CreateDocumentAction $action,
        DocumentLifecycleAccess $access,
    ) {
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

        $request->attributes->set('document_lifecycle_can_mutate', $access->canMutate($request->user()));

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

        $request->attributes->set('document_lifecycle_can_mutate', $access->canMutate($request->user()));

        return ApiResponse::success(
            (new DocumentLifecycleResource($document))->resolve($request),
            'Document lifecycle detail retrieved successfully.',
        );
    }

    public function responsibleOptions(Request $request, $document_id, DocumentLifecycleAccess $access)
    {
        if ($request->user()->cannot(InstitutionAbility::ManageDocuments->value)) {
            return ApiResponse::error('DOCUMENT_RESPONSIBILITY_FORBIDDEN', 'You are not allowed to manage document responsibility.', 403);
        }
        $validator = Validator::make($request->query(), ['q' => ['required', 'string', 'min:2', 'max:100']]);
        if ($validator->fails()) {
            return ApiResponse::error('VALIDATION_FAILED', 'The responsible user search request is invalid.', 422, $validator->errors()->toArray());
        }
        if (! $access->findDocument($request->user(), $document_id)) {
            return ApiResponse::error('DOCUMENT_NOT_AVAILABLE', 'The document is not available.', 404);
        }

        $term = mb_strtolower($validator->validated()['q']);
        $users = User::query()
            ->where('institution_id', $request->user()->institution_id)
            ->where('active', true)
            ->where(function (Builder $query) use ($term): void {
                $query->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$term}%"]);
            })
            ->orderBy('name')->orderBy('id')->limit(10)->get(['id', 'name', 'email'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])->all();

        return ApiResponse::success(['users' => $users], 'Responsible user options retrieved successfully.');
    }

    public function updateResponsible(
        Request $request,
        $document_id,
        DocumentLifecycleAccess $access,
        UpdateDocumentResponsibilityAction $action,
    ) {
        if ($request->user()->cannot(InstitutionAbility::ManageDocuments->value)) {
            return ApiResponse::error('DOCUMENT_RESPONSIBILITY_FORBIDDEN', 'You are not allowed to manage document responsibility.', 403);
        }
        $validator = Validator::make($request->all(), [
            'responsible_user_id' => ['present', 'nullable', 'uuid'],
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);
        foreach (array_diff(array_keys($request->all()), ['responsible_user_id', 'expected_revision']) as $field) {
            $validator->errors()->add($field, "The {$field} field is not allowed.");
        }
        if ($validator->fails()) {
            return ApiResponse::error('VALIDATION_FAILED', 'The document responsibility request is invalid.', 422, $validator->errors()->toArray());
        }
        if (! $access->findDocument($request->user(), $document_id)) {
            return ApiResponse::error('DOCUMENT_NOT_AVAILABLE', 'The document is not available.', 404);
        }

        try {
            $document = $action->execute(
                $request->user(), $document_id, $validator->validated()['responsible_user_id'],
                $validator->validated()['expected_revision'],
            );
        } catch (DocumentResponsibilityException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
        } catch (\Throwable) {
            return ApiResponse::error('DOCUMENT_RESPONSIBILITY_FAILED', 'The document responsibility could not be updated.', 500);
        }

        $responsible = $document->responsibleUser;

        return ApiResponse::success([
            'responsible' => $responsible ? [
                'id' => $responsible->id,
                'name' => $responsible->name,
                'active' => (bool) $responsible->active && ! $responsible->trashed(),
            ] : null,
            'responsibility_revision' => (int) $document->responsibility_revision,
        ], 'Document responsibility updated successfully.');
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
