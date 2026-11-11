<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\EmailVerificationLink;
use App\Services\OnboardingStateResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EmailVerificationController extends Controller
{
    public function resend(Request $request)
    {
        // TODO(email): invoke verification delivery for the authenticated user when implemented.
        // Do not send or enqueue notifications, expose a link, or mark the account verified yet.
        return ApiResponse::success(['delivery_status' => 'disabled'], 'Email delivery is not available.')
            ->setStatusCode(202);
    }

    public function verify(Request $request, EmailVerificationLink $links)
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'uuid'],
            'hash' => ['required', 'string', 'size:40'],
            'expires' => ['required', 'integer'],
            'signature' => ['required', 'string', 'size:64'],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('EMAIL_VERIFICATION_INVALID', 'The verification link is invalid or expired.', 403);
        }
        $parameters = $validator->validated();
        if (! hash_equals((string) $request->user()->id, $parameters['id']) || ! $links->isValid($parameters)) {
            return ApiResponse::error('EMAIL_VERIFICATION_INVALID', 'The verification link is invalid or expired.', 403);
        }

        $user = DB::transaction(function () use ($request, $parameters): ?User {
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals(sha1($user->getEmailForVerification()), $parameters['hash'])) {
                return null;
            }
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            return $user;
        });
        if ($user === null) {
            return ApiResponse::error('EMAIL_VERIFICATION_INVALID', 'The verification link is invalid or expired.', 403);
        }

        return ApiResponse::success([
            'email_verified' => true,
            'onboarding' => app(OnboardingStateResolver::class)->resolve($user),
        ], 'Email verified.');
    }
}
