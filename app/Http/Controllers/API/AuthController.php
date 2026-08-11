<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends BaseController
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'The given data was invalid.',
                422,
                $validator->errors()->toArray(),
            );
        }

        $user = User::query()->where('email', $request->string('email')->toString())->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password) || ! $user->email_verified_at) {
            return ApiResponse::error(
                'AUTH_INVALID_CREDENTIALS',
                'The provided credentials are invalid.',
                401,
            );
        }

        if (! $user->active) {
            return ApiResponse::error(
                'AUTH_ACCOUNT_INACTIVE',
                'This account is inactive.',
                403,
            );
        }

        $user->load(['institution:id,name', 'roles:id,type']);

        if (! $user->institution) {
            return ApiResponse::error(
                'AUTH_INSTITUTION_REQUIRED',
                'A valid institution is required to access the application.',
                403,
            );
        }

        $userData = (new UserResource($user))->resolve($request);
        $plainTextToken = $user->createToken(($user->name ?? 'user').'-AuthToken')->plainTextToken;

        return ApiResponse::success([
            'user' => $userData,
            'token' => $plainTextToken,
        ], 'Login successful.');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Logout successful.');
    }

    public function sendResetLink(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        $user = User::whereEmail($request->email)->first();

        $token = Str::random(60);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            [
                'email' => $user->email,
                'token' => $token,
                'created_at' => now(),
            ]);

        \Mail::to($user->email)->send(new ResetPasswordMail($token, $user->email));

        return $this->sendResponse([], 'We have emailed your password reset link.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        $reset = DB::table('password_reset_tokens')->where('token', $request->token)->first();

        if (! $reset) {
            return $this->sendError('Invalid token.', '', 400);
        }

        if (! $reset || Carbon::parse($reset->created_at)->addMinutes(60)->isPast()) {
            return $this->sendError('Expired token.', '', 400);
        }

        $user = User::whereEmail($reset->email)->first();
        $user->update(['email_verified_at' => now(), 'password' => $request->password]);
        $user->save();

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        return $this->sendResponse([], 'Password has been reset successfully.');
    }
}
