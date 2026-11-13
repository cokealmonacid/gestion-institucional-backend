<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\AuthSessionIssuer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterAccountAction
{
    public function execute(array $attributes, Request $request): array
    {
        try {
            $session = DB::transaction(function () use ($attributes, $request): array {
                $user = User::create([
                    'name' => $attributes['name'],
                    'email' => $attributes['email'],
                    'password' => $attributes['password'],
                    'active' => true,
                ]);

                // Do not emit Registered: its listeners may send email implicitly.
                return app(AuthSessionIssuer::class)->issue($user, $request);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! DB::table('users')->where('email', $attributes['email'])->exists()) {
                throw $exception;
            }

            throw ValidationException::withMessages(['email' => ['El correo ya está registrado.']]);
        }

        // TODO(email): invoke verification delivery here, after commit, when the service exists.
        // For now do not send, enqueue, return a verification link, or auto-verify the account.
        return $session + ['delivery_status' => 'disabled'];
    }
}
