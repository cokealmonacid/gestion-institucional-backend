<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Validator;

class UsersController extends BaseController
{
    public function profile(Request $request)
    {
        $user = $request->user()->load(['institution:id,name', 'roles:id,type']);
        $userData = (new UserResource($user))->resolve($request);

        return ApiResponse::success([
            'user' => $userData,
        ], 'Profile retrieved successfully.');
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = validator($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        $user->update($request->only('name'));

        return response()->json([
            'data' => [
                'name' => $user->name,
                'email' => $user->email,
                'token' => null,
                'institution_id' => $user->institution_id,
            ],
        ]);
    }

    public function updatePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'bail|required|min:8',
            'new_password' => 'bail|required|min:8|different:password',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        $user = auth()->user();

        if (! Hash::check($request->password, $user->password)) {
            return $this->sendError('Validation failed.', ['error' => 'The current password is incorrect'], 405);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return $this->sendResponse([], 'Password has been udpated successfully.');
    }
}
