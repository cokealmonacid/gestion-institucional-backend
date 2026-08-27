<?php

namespace Modules\Documents\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentTag;
use Modules\Institution\Models\Tag;

class DocumentTagsController extends BaseController
{
    public function store(Request $request, $document_id)
    {
        $validator = Validator::make($request->all(), [
            'tag_id' => ['required'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        if (! $this->documentAndTagsAreAvailable($request, $document_id, [$request->tag_id])) {
            return $this->sendError('Document or tag not found.', [], 404);
        }

        $assignmentValidator = Validator::make($request->all(), [
            'tag_id' => [
                Rule::unique('document_tags', 'tag_id')->where('document_id', $document_id),
            ],
        ], [
            'tag_id.unique' => 'Tag already assigned to Document.',
        ]);

        if ($assignmentValidator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $assignmentValidator->errors()], 422);
        }

        try {
            DocumentTag::create([
                'document_id' => $document_id,
                'tag_id' => $request->tag_id,
                'assigned_by_id' => auth()->id(),
            ]);

            return $this->sendResponse(null, 'Tag added successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
        }
    }

    public function update(Request $request, $document_id)
    {
        $validator = Validator::make(
            array_merge($request->all(), ['document_id' => $document_id]),
            [
                'tags_id' => 'required|array|min:1',
                'tags_id.*' => [
                    'required',
                    'distinct',
                ],
            ]
        );

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        if (! $this->documentAndTagsAreAvailable($request, $document_id, $request->tags_id)) {
            return $this->sendError('Document or tag not found.', [], 404);
        }

        try {
            DocumentTag::where('document_id', $document_id)->delete();

            $tags = array_map(fn ($tag_id) => [
                'document_id' => $document_id,
                'tag_id' => $tag_id,
                'assigned_by_id' => auth()->id(),
            ], $request->tags_id);

            DocumentTag::insert($tags);

            return $this->sendResponse(null, 'Tags updated successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
        }
    }

    public function destroy(Request $request, $document_id)
    {
        $validator = Validator::make($request->all(), [
            'tag_id' => ['required'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        if (! $this->documentAndTagsAreAvailable($request, $document_id, [$request->tag_id])) {
            return $this->sendError('Document or tag not found.', [], 404);
        }

        try {
            DocumentTag::where([
                'tag_id' => $request->tag_id,
                'document_id' => $document_id,
            ])->delete();

            return $this->sendResponse(null, 'Tag removed successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
        }
    }

    /** @param array<int, string> $tagIds */
    private function documentAndTagsAreAvailable(Request $request, string $documentId, array $tagIds): bool
    {
        $institutionId = $request->user()->institution_id;
        $documentExists = Document::where('institution_id', $institutionId)
            ->whereKey($documentId)
            ->exists();
        $tagCount = Tag::where('institution_id', $institutionId)
            ->whereIn('id', array_unique($tagIds))
            ->count();

        return $documentExists && $tagCount === count(array_unique($tagIds));
    }
}
