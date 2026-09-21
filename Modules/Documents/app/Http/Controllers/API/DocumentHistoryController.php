<?php

namespace Modules\Documents\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator;
use Modules\Documents\Http\Resources\DocumentEventResource;
use Modules\Documents\Models\DocumentEvent;
use Modules\Documents\Services\DocumentLifecycleAccess;

class DocumentHistoryController extends BaseController
{
    public function index(Request $request, $document_id, DocumentLifecycleAccess $access)
    {
        $validator = Validator::make($request->query(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ]);
        foreach (array_diff(array_keys($request->query()), ['limit', 'cursor']) as $field) {
            $validator->errors()->add($field, "The {$field} field is not allowed.");
        }
        if ($validator->fails()) {
            return ApiResponse::error(
                'VALIDATION_FAILED',
                'The document history request is invalid.',
                422,
                $validator->errors()->toArray(),
            );
        }

        $document = $access->findDocument($request->user(), $document_id);
        if (! $document) {
            return ApiResponse::error('DOCUMENT_NOT_AVAILABLE', 'The document is not available.', 404);
        }

        $cursor = null;
        if ($request->has('cursor')) {
            $cursor = $this->validatedCursor((string) $request->query('cursor'), $document->id, $document->institution_id);
            if (! $cursor) {
                return ApiResponse::error(
                    'VALIDATION_FAILED',
                    'The document history request is invalid.',
                    422,
                    ['cursor' => ['The cursor is invalid for this document history.']],
                );
            }
        }

        $events = DocumentEvent::query()
            ->where('document_id', $document->id)
            ->where('institution_id', $document->institution_id)
            ->with('actor:id,name,active,deleted_at,institution_id')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->query('limit', 20), ['*'], 'cursor', $cursor);

        return response()->json([
            'success' => true,
            'data' => DocumentEventResource::collection($events->items())->resolve($request),
            'meta' => ['next_cursor' => $events->nextCursor()?->encode()],
            'message' => 'Document history retrieved successfully.',
        ]);
    }

    private function validatedCursor(string $encoded, string $documentId, string $institutionId): ?Cursor
    {
        try {
            $json = base64_decode(strtr($encoded, '-_', '+/'), true);
            $decoded = $json === false ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($decoded)
            || array_keys($decoded) !== ['occurred_at', 'id', '_pointsToNextItems']
            || ! is_string($decoded['occurred_at'])
            || ! is_string($decoded['id'])
            || ! is_bool($decoded['_pointsToNextItems'])
            || ! $decoded['_pointsToNextItems']) {
            return null;
        }

        $boundaryExists = DocumentEvent::query()
            ->whereKey($decoded['id'])
            ->where('document_id', $documentId)
            ->where('institution_id', $institutionId)
            ->where('occurred_at', $decoded['occurred_at'])
            ->exists();

        return $boundaryExists ? new Cursor([
            'occurred_at' => $decoded['occurred_at'],
            'id' => $decoded['id'],
        ], true) : null;
    }
}
