<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class EmailVerificationLink
{
    // Internal only: never expose a usable verification link through the public API.
    public function forUser(User $user): string
    {
        return URL::temporarySignedRoute('api.verification.verify',
            now()->addMinutes(config('onboarding.verification_minutes')),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
        );
    }

    public function isValid(array $parameters): bool
    {
        $signature = $parameters['signature'];
        unset($parameters['signature']);
        ksort($parameters);
        $parameters['signature'] = $signature;

        // Reconstruct a canonical API URL, never a caller-provided URL or redirect.
        $signedRequest = Request::create(route('api.verification.verify', $parameters));

        return URL::hasValidSignature($signedRequest);
    }
}
