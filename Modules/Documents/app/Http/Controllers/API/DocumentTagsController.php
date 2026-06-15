<?php

namespace Modules\Documents\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use Modules\Documents\Models\DocumentTag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Http\Request;
use Validator;

class DocumentTagsController extends BaseController
{
    private function institutionDocumentRule(Request $request): Exists
    {
        return Rule::exists('documents', 'id')
            ->where('institution_id', $request->user()->institution_id);
    }

    private function institutionTagRule(Request $request): Exists
    {
        return Rule::exists('tags', 'id')
            ->where('institution_id', $request->user()->institution_id);
    }

    public function store(Request $request, $document_id)
    {
        $validator = Validator::make(array_merge($request->all(), ['document_id' => $document_id]), [
            'document_id' => ['required', $this->institutionDocumentRule($request)],
            'tag_id' => [
                'required',
                $this->institutionTagRule($request),
                Rule::unique('document_tags', 'tag_id')->where('document_id', $document_id),
            ],
        ], [
            'tag_id.unique' => 'Tag already assigned to Document.',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error'=> $validator->errors()], 422);
        }

        try {
            DocumentTag::create([
                'document_id' => $document_id,
                'tag_id' => $request->tag_id,
                'assigned_by_id' => auth()->id()
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
                'document_id' => ['required', $this->institutionDocumentRule($request)],
                'tags_id' => 'required|array|min:1',
                'tags_id.*' => [
                    'required',
                    'distinct',
                    $this->institutionTagRule($request),
                ],
            ]
        );

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error'=> $validator->errors()], 422);
        }

        try {
            DocumentTag::where('document_id', $document_id)->delete();

            $tags = array_map(fn($tag_id) => [
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
        $validator = Validator::make(array_merge($request->all(), ['document_id' => $document_id]), [
            'document_id' => ['required', $this->institutionDocumentRule($request)],
            'tag_id' => ['required', $this->institutionTagRule($request)],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error'=> $validator->errors()], 422);
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
}
