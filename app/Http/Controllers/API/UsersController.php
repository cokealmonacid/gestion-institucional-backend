<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class UsersController extends BaseController
{
    public function profile(Request $request)
    {
        return new UserResource($request->user());
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = validator($request->all(), [
            'name' => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        $user->update($request->only('name'));

        return new UserResource($user);
    }
}
