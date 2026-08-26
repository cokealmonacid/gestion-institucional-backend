<?php

namespace Modules\Institution\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Institution\Http\Resources\TagResource;
use Modules\Institution\Models\Tag;

class TagsController extends BaseController
{
    public function index(Request $request)
    {
        $query = Tag::where('institution_id', $request->user()->institution_id);
        if ($request->status) {
            $query->where('status', $request->status);
        }

        $tags = $query->paginate($request->input('per_page', 15));
        $tags->getCollection()->transform(
            fn (Tag $tag) => (new TagResource($tag))->resolve($request)
        );

        return $this->sendResponse($tags, 'Tags retrieved successfully.');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'institution_id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        if ((string) $request->input('institution_id') !== (string) $request->user()->institution_id) {
            return $this->sendError('Institution not found.', [], 404);
        }

        try {
            $tag = Tag::create([
                'name' => $request->name,
                'description' => $request->description,
                'institution_id' => $request->user()->institution_id,
            ]);

            return $this->sendResponse([
                'id' => $tag->id,
                'name' => $tag->name,
                'description' => $tag->description,
            ], 'Tag created successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $validator = Validator::make(array_merge($request->all(), ['id' => $id]), [
            'id' => ['required', 'uuid'],
            'institution_id' => ['required', 'uuid'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        if ((string) $request->input('institution_id') !== (string) $request->user()->institution_id) {
            return $this->sendError('Tag not found.', [], 404);
        }

        try {
            $tag = Tag::where('institution_id', $request->user()->institution_id)->find($id);

            if (! $tag) {
                return $this->sendError('Tag not found.', [], 404);
            }

            $tag->delete();

            return $this->sendResponse(null, 'Tag removed successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
        }
    }
}
