<?php

namespace Modules\Documents\Http\Controllers\API;

use App\Enums\InstitutionAbility;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Modules\Documents\Actions\UpdateDocumentVersionNoteAction;
use Modules\Documents\Exceptions\DocumentVersionNoteException;
use Modules\Documents\Http\Requests\ListDocumentVersionNoteHistoryRequest;
use Modules\Documents\Http\Requests\UpdateDocumentVersionNoteRequest;
use Modules\Documents\Http\Resources\DocumentVersionNoteHistoryResource;
use Modules\Documents\Http\Resources\DocumentVersionNoteResource;
use Modules\Documents\Models\DocumentVersionCommentHistory;
use Modules\Documents\Services\DocumentEventRecorder;
use Modules\Documents\Services\DocumentVersionNoteAccess;

class DocumentVersionNotesController extends Controller
{
    public function index(Request $request, $document_id, DocumentVersionNoteAccess $access)
    {
        if ($request->user()->cannot(InstitutionAbility::ViewTraceability->value)) {
            return ApiResponse::error(
                'DOCUMENT_VERSION_NOTE_HISTORY_FORBIDDEN',
                'No tienes permiso para consultar notas de versiones.',
                403,
            );
        }

        $document = $access->findDocument($request->user(), $document_id);
        if (! $document) {
            return ApiResponse::error('DOCUMENT_NOT_AVAILABLE', 'El documento no está disponible.', 404);
        }

        $versions = $document->versions()
            ->where('institution_id', $document->institution_id)
            ->with('latestCommentHistory.user')
            ->orderByDesc('version_number')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(
            DocumentVersionNoteResource::collection($versions)->resolve($request),
            'Notas de versiones obtenidas correctamente.',
        );
    }

    public function history(
        ListDocumentVersionNoteHistoryRequest $request,
        $document_id,
        $version_id,
        DocumentVersionNoteAccess $access,
    ) {
        $document = $access->findDocument($request->user(), $document_id);
        if (! $document) {
            return ApiResponse::error('DOCUMENT_NOT_AVAILABLE', 'El documento no está disponible.', 404);
        }

        $version = $access->findVersion($document, $version_id);
        if (! $version) {
            return ApiResponse::error('DOCUMENT_VERSION_NOT_AVAILABLE', 'La versión no está disponible.', 404);
        }

        $cursor = null;
        if ($request->has('cursor')) {
            $cursor = $this->validatedCursor((string) $request->query('cursor'), $document->id, $version->id);
            if (! $cursor) {
                return ApiResponse::error(
                    'VALIDATION_FAILED',
                    'La solicitud de historial de notas no es válida.',
                    422,
                    ['cursor' => ['El cursor no es válido para este historial de notas.']],
                );
            }
        }

        $histories = DocumentVersionCommentHistory::query()
            ->where('document_id', $document->id)
            ->where('document_version_id', $version->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->query('limit', 20), ['*'], 'cursor', $cursor);

        return response()->json([
            'success' => true,
            'data' => DocumentVersionNoteHistoryResource::collection($histories->items())->resolve($request),
            'meta' => ['next_cursor' => $histories->nextCursor()?->encode()],
            'message' => 'Historial de notas obtenido correctamente.',
        ]);
    }

    public function update(
        UpdateDocumentVersionNoteRequest $request,
        $document_id,
        $version_id,
        UpdateDocumentVersionNoteAction $action,
        DocumentEventRecorder $events,
    ) {
        try {
            $result = $action->execute(
                $request->user(),
                $document_id,
                $version_id,
                $request->note(),
                $events,
            );
        } catch (DocumentVersionNoteException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return ApiResponse::success([
            'note' => (new DocumentVersionNoteResource($result['version']))->resolve($request),
            'transition' => (new DocumentVersionNoteHistoryResource($result['history']))->resolve($request),
        ], $result['history']->new_comment === null
            ? 'Nota de versión eliminada correctamente.'
            : 'Nota de versión actualizada correctamente.');
    }

    private function validatedCursor(string $encoded, string $documentId, string $versionId): ?Cursor
    {
        try {
            $json = base64_decode(strtr($encoded, '-_', '+/'), true);
            $decoded = $json === false ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($decoded)
            || array_keys($decoded) !== ['created_at', 'id', '_pointsToNextItems']
            || ! is_string($decoded['created_at'])
            || ! is_string($decoded['id'])
            || ! is_bool($decoded['_pointsToNextItems'])
            || ! $decoded['_pointsToNextItems']) {
            return null;
        }

        $boundaryExists = DocumentVersionCommentHistory::query()
            ->whereKey($decoded['id'])
            ->where('document_id', $documentId)
            ->where('document_version_id', $versionId)
            ->where('created_at', $decoded['created_at'])
            ->exists();

        return $boundaryExists ? new Cursor([
            'created_at' => $decoded['created_at'],
            'id' => $decoded['id'],
        ], true) : null;
    }
}
