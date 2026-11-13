<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Rules\UserPassword;
use App\Services\AuthSessionIssuer;
use App\Services\UserAccess\InvitationService;
use App\Services\UserAccess\PasswordRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $user = User::query()->whereRaw('LOWER(email) = ?', [Str::lower(trim($request->string('email')->toString()))])->first();

        if (! $user || ! is_string($user->password) || ! Hash::check($request->string('password')->toString(), $user->password)) {
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

        return ApiResponse::success(
            app(AuthSessionIssuer::class)->issue($user, $request),
            'Login successful.',
        );
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Logout successful.');
    }

    public function requestPasswordRecovery(Request $request, PasswordRecoveryService $recovery): JsonResponse
    {
        if ($response = $this->rejectUnexpected($request, ['email'])) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('VALIDATION_ERROR', 'The given data was invalid.', 422, $validator->errors()->toArray());
        }

        $recovery->request($request->string('email')->lower()->toString());

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'If the account is eligible, recovery instructions will be sent.',
        ], 202);
    }

    public function resetPassword(Request $request, PasswordRecoveryService $recovery): JsonResponse
    {
        if ($response = $this->rejectUnexpected($request, ['email', 'token', 'password', 'password_confirmation'])) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => UserPassword::rules(),
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('VALIDATION_ERROR', 'The given data was invalid.', 422, $validator->errors()->toArray());
        }

        if (! $recovery->reset($validator->validated())) {
            return ApiResponse::error('PASSWORD_RESET_LINK_INVALID', 'The password reset link is invalid or unavailable.', 400);
        }

        return ApiResponse::success(null, 'Password reset successfully. Sign in again.');
    }

    public function activate(Request $request, InvitationService $invitations): JsonResponse
    {
        if ($response = $this->rejectUnexpected($request, ['email', 'token', 'password', 'password_confirmation'])) {
            return $response;
        }

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => UserPassword::rules(),
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('VALIDATION_ERROR', 'The given data was invalid.', 422, $validator->errors()->toArray());
        }

        if (! $invitations->activate($validator->validated())) {
            return ApiResponse::error('ACTIVATION_LINK_INVALID', 'The activation link is invalid or unavailable.', 400);
        }

        return ApiResponse::success(null, 'Account activated successfully. Sign in to continue.');
    }

    /** @param array<int, string> $allowed */
    private function rejectUnexpected(Request $request, array $allowed): ?JsonResponse
    {
        $unexpected = array_values(array_diff(array_keys($request->all()), $allowed));

        return $unexpected === [] ? null : ApiResponse::error(
            'VALIDATION_ERROR',
            'The given data was invalid.',
            422,
            ['request' => ['Unexpected properties: '.implode(', ', $unexpected).'.']],
        );
    }
}
