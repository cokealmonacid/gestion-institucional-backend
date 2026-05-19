<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Validator;

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

    public function updatePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'bail|required|min:8',
            'new_password' => 'bail|required|min:8|different:password'
        ]);


        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error'=> $validator->errors()], 422);
        }

        $user = auth()->user();

        if (!Hash::check($request->password, $user->password)) {
            return $this->sendError('Validation failed.', ['error' => 'The current password is incorrect'], 405);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return $this->sendResponse([], 'Password has been udpated successfully.');
    }
}
