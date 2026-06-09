<?php

namespace Modules\Documents\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentTag;
use Modules\Institution\Models\Tag;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Validator;

class DocumentTagsController extends BaseController
{
    public function store(Request $request, $document_id) 
    {
        $validator = Validator::make(array_merge($request->all(), ['document_id' => $document_id]), [
            'document_id' => 'required|exists:documents,id',
            'tag_id' => 'required|exists:tags,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error'=> $validator->errors()], 422);
        }

        $institution_id = auth()->user()->institution_id;
        $tag = Tag::where('id', $request->tag_id)->where('institution_id', $institution_id)->first();
        $document = Document::where('id', $document_id)->where('institution_id', $institution_id)->first();
        if (!$tag || !$document) {
            return $this->sendError('Tag or Document not found.', [], 404);
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
        $institution_id = auth()->user()->institution_id;
        $validator = Validator::make(
            array_merge($request->all(), ['document_id' => $document_id]),
            [
                'document_id' => 'required|exists:documents,id',
                'tags_id' => 'required|array|min:1',
                'tags_id.*' => [
                    'required',
                    'distinct',
                    Rule::exists('tags', 'id')
                        ->where('institution_id', $institution_id),
                ],
            ]
        );

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error'=> $validator->errors()], 422);
        }

        $document = Document::where('id', $document_id)->where('institution_id', $institution_id)->first();
        if (!$document) {
            return $this->sendError('Document not found.', [], 404);
        }

        try {
            foreach ($request->tags_id as $tag_id) {
                DocumentTag::firstOrCreate(
                    [
                        'document_id' => $document_id,
                        'tag_id' => $tag_id,
                    ],
                    [
                        'assigned_by_id' => auth()->id(),
                    ]
                );
            }

            return $this->sendResponse(null, 'Tag added successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
        }
    }

    public function destroy(Request $request, $document_id) 
    {
        $validator = Validator::make(array_merge($request->all(), ['document_id' => $document_id]), [
            'document_id' => 'required|exists:documents,id',
            'tag_id' => 'required|exists:tags,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error'=> $validator->errors()], 422);
        }

        try {
            $find = ['tag_id' => $request->tag_id, 'document_id' => $document_id];
            $delete = DocumentTag::where($find)->delete();

            return $this->sendResponse(null, 'Tag removed successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
        }
    }
}
