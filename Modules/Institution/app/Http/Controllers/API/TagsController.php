<?php

namespace Modules\Institution\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use App\Http\Controllers\Controller;
use Modules\Institution\Models\Tag;
use Illuminate\Http\Request;
use Validator;

class TagsController extends BaseController
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'institution_id' => 'required|exists:institutions,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error'=> $validator->errors()], 422);
        }

        $user = auth()->user();
        if ($user->institution_id != $request->institution_id) {
            return $this->sendError('Unauthorized.', [], 403);
        }
        
        try {
            Tag::create([
                'name' => $request->name,
                'description' => $request->description,
                'institution_id' => $request->institution_id,
            ]);

            return $this->sendResponse(null, 'Tag created successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $validator = Validator::make(array_merge($request->all(), ['id' => $id]), [
            'id' => 'required|exists:comments,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error'=> $validator->errors()], 422);
        }

        try {
            $tag = Tag::findOrFail($id);
            $tag->delete();

            return $this->sendResponse(null, 'Tag removed successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
        }
    } 
}
