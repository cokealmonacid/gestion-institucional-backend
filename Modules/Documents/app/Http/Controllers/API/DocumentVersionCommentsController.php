<?php

namespace Modules\Documents\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentVersion;
use Modules\Documents\Models\DocumentVersionCommentHistory;

class DocumentVersionCommentsController extends BaseController
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

    private function findVersion(Document $document, string $versionId): ?DocumentVersion
    {
        return $this->documentVersionQuery($document)->find($versionId);
    }

    public function index(Request $request, $document_id)
    {
        $document = $this->findDocument($request, $document_id);

        if (!$document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $latestChanges = DocumentVersionCommentHistory::select('document_version_id', DB::raw('MAX(created_at) as last_comment_changed_at'))
            ->where('document_id', $document->id)
            ->groupBy('document_version_id');

        $versions = $this->documentVersionQuery($document)
            ->where('active', true)
            ->leftJoinSub($latestChanges, 'latest_comment_changes', function ($join) {
                $join->on('document_versions.id', '=', 'latest_comment_changes.document_version_id');
            })
            ->with(['author:id,name,email', 'latestCommentHistory.user:id,name,email'])
            ->orderByRaw('COALESCE(latest_comment_changes.last_comment_changed_at, document_versions.updated_at) DESC')
            ->select('document_versions.*')
            ->get()
            ->map(function (DocumentVersion $version) {
                $latestHistory = $version->latestCommentHistory;
                $lastUser = $latestHistory?->user ?? $version->author;

                return [
                    'version_id' => $version->id,
                    'version_number' => $version->version_number,
                    'filename' => $version->filename,
                    'comment' => $version->comment,
                    'is_current' => $version->is_current,
                    'last_comment_user' => $lastUser ? [
                        'id' => $lastUser->id,
                        'name' => $lastUser->name,
                        'email' => $lastUser->email,
                    ] : null,
                    'last_comment_changed_at' => $latestHistory?->created_at ?? $version->updated_at,
                ];
            });

        return $this->sendResponse($versions, 'Document version comments retrieved successfully.');
    }

    public function history(Request $request, $document_id, $version_id)
    {
        $document = $this->findDocument($request, $document_id);

        if (!$document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $version = $this->findVersion($document, $version_id);

        if (!$version) {
            return $this->sendError('Document version not found.', [], 404);
        }

        $histories = DocumentVersionCommentHistory::with('user:id,name,email')
            ->where('document_id', $document->id)
            ->where('document_version_id', $version->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->sendResponse($histories, 'Document version comment history retrieved successfully.');
    }

    public function update(Request $request, $document_id, $version_id)
    {
        $document = $this->findDocument($request, $document_id);

        if (!$document) {
            return $this->sendError('Document not found.', [], 404);
        }

        $version = $this->findVersion($document, $version_id);

        if (!$version) {
            return $this->sendError('Document version not found.', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'comment' => ['present', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $newComment = $request->input('comment');

        if ($version->comment === $newComment) {
            return $this->sendError('Comment must be different from the current comment.', [], 422);
        }

        $history = DB::transaction(function () use ($request, $document, $version, $newComment) {
            $lockedVersion = $this->documentVersionQuery($document)
                ->lockForUpdate()
                ->findOrFail($version->id);

            if ($lockedVersion->comment === $newComment) {
                return null;
            }

            $previousComment = $lockedVersion->comment;
            $lockedVersion->comment = $newComment;
            $lockedVersion->save();

            return DocumentVersionCommentHistory::create([
                'document_id' => $document->id,
                'document_version_id' => $lockedVersion->id,
                'user_id' => $request->user()->id,
                'previous_comment' => $previousComment,
                'new_comment' => $newComment,
            ]);
        });

        if (!$history) {
            return $this->sendError('Comment must be different from the current comment.', [], 422);
        }

        $version->refresh();

        return $this->sendResponse([
            'version' => $version,
            'history' => $history->load('user:id,name,email'),
        ], 'Document version comment updated successfully.');
    }
}
