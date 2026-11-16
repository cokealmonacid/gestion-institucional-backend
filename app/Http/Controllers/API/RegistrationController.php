<?php

namespace App\Http\Controllers\API;

use App\Actions\Auth\RegisterAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    public function store(Request $request, RegisterAccountAction $action)
    {
        $input = $request->all();
        if (is_string($input['email'] ?? null)) {
            $input['email'] = Str::lower(trim($input['email']));
        }
        if (is_string($input['name'] ?? null)) {
            $input['name'] = trim($input['name']);
        }
        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ], ['email.unique' => 'El correo ya está registrado.']);
        $validator->after(function ($validator) use ($input): void {
            if (! $validator->errors()->has('email')
                && DB::table('users')->whereRaw('LOWER(email) = ?', [$input['email']])->exists()) {
                $validator->errors()->add('email', 'El correo ya está registrado.');
            }
            foreach (array_diff(array_keys($input), ['name', 'email', 'password', 'password_confirmation']) as $field) {
                $validator->errors()->add($field, 'This field is not allowed.');
            }
        });

        try {
            $attributes = $validator->validate();
            $session = $action->execute($attributes, $request);
        } catch (ValidationException $exception) {
            return ApiResponse::error('VALIDATION_ERROR', 'The given data was invalid.', 422, $exception->errors());
        }

        return ApiResponse::success($session, 'Account created. Email delivery is not available.')
            ->setStatusCode(201);
    }
}
