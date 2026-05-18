<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Validator;

class AuthController extends BaseController
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'bail|required|string|email|exists:users,email',
            'password' => 'required|min:8'
        ], [
            'email.exists' => 'The email does not exist in our records.',
        ]);
        
        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        if (!Auth::attempt(['email' => $request['email'], 'password' => $request['password']])) {
            return $this->sendError('Unauthorized', ['error' => 'The password is incorrect.'], 401);
        }

        $user = Auth::user();
        if (!$user->email_verified_at) {
            return $this->sendError('Unauthorized', ['error' => 'Your account is not verified.'], 401);
        }

        $response = new UserResource($user);
        $response['token'] = $user->createToken(($user->name ?? 'user') . '-AuthToken')->plainTextToken;

        return $this->sendResponse($response, 'User login successfully.');
    }

    public function logout()
    {
        auth()->user()->tokens()->delete();

        return $this->sendResponse([], 'User logout successfully.');
    }
}
