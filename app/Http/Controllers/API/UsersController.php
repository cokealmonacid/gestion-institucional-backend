<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Rules\UserPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Modules\Institution\Models\Institution;

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
        $unexpected = array_values(array_diff(array_keys($request->all()), ['current_password', 'password', 'password_confirmation']));
        if ($unexpected !== []) {
            return ApiResponse::error('VALIDATION_ERROR', 'The given data was invalid.', 422, [
                'request' => ['Unexpected properties: '.implode(', ', $unexpected).'.'],
            ]);
        }

        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string'],
            'password' => array_merge(UserPassword::rules(), ['different:current_password']),
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        $user = auth()->user();

        $changed = DB::transaction(function () use ($user, $request): bool {
            Institution::query()->whereKey($user->institution_id)->lockForUpdate()->firstOrFail();
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! is_string($locked->password) || ! Hash::check($request->current_password, $locked->password)) {
                return false;
            }
            $locked->password = $request->password;
            $locked->save();
            DB::table('password_reset_tokens')->where('email', $locked->email)->delete();
            $locked->tokens()->delete();

            return true;
        }, 3);

        if (! $changed) {
            return ApiResponse::error('CURRENT_PASSWORD_INVALID', 'The current password is incorrect.', 422);
        }

        return ApiResponse::success(null, 'Password updated successfully. Sign in again.');
    }

    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:2|max:100',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        $q = $request->query('q');

        $users = User::query()
            ->where('institution_id', $request->user()->institution_id)
            ->where('active', true)
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'email']);

        return ApiResponse::success(['users' => $users], 'Users retrieved successfully.');
    }
}
