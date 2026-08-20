<?php

namespace Modules\Institution\Http\Controllers\API;

use App\Enums\RoleType;
use App\Http\Controllers\BaseController;
use App\Models\Rol;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Institution\Http\Resources\UserResource;

class UsersController extends BaseController
{
    public function index(Request $request)
    {
        $users = User::where('institution_id', $request->user()->institution_id)
            ->where('users.id', '!=', $request->user()->id)
            ->with('roles')
            ->paginate($request->input('per_page', 15));

        $users->getCollection()->transform(
            fn (User $user) => (new UserResource($user))->resolve($request)
        );

        return $this->sendResponse($users, 'Users retrieved successfully.');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'bail|required|string|email|unique:users,email',
            'password' => 'required|min:8',
            'password_confirmation' => 'required|same:password',
            'institution_id' => 'required|exists:institutions,id',
            'rol' => ['required', Rule::enum(RoleType::class)],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        try {
            $input = $request->all();
            $rol = Rol::whereType($request->rol)->first();
            $input['password'] = bcrypt($input['password']);

            $user = User::create($input);
            $user->save();

            RoleUser::create([
                'user_id' => $user->id,
                'role_id' => $rol->id,
            ]);

            return $this->sendResponse([], 'Account registered successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
        }
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|exists:users,email',
            'name' => 'sometimes|required|string|max:255',
            'active' => 'sometimes|required|boolean',
            'role' => ['sometimes', 'required', Rule::enum(RoleType::class)],
            'institution_id' => [
                'required',
                'exists:institutions,id',
                function ($attribute, $value, $fail) use ($request) {
                    $user = User::whereEmail($request->email)->first();
                    if ($user && $user->institution_id != $value) {
                        $fail('User does not belong to this institution.');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        try {
            $user = User::whereEmail($request->email)->first();
            $input = $request->except(['role', 'email', 'institution_id']);

            $user->update($input);

            if ($request->filled('role')) {
                $rol = Rol::whereType($request->role)->first();

                RoleUser::where('user_id', $user->id)->delete();

                RoleUser::firstOrCreate([
                    'user_id' => $user->id,
                    'role_id' => $rol->id,
                ]);
            }

            return $this->sendResponse([], 'Account updated successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
        }
    }

    public function destroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|exists:users,email',
            'institution_id' => [
                'required',
                'exists:institutions,id',
                function ($attribute, $value, $fail) use ($request) {
                    $user = User::whereEmail($request->email)->first();
                    if ($user && $user->institution_id != $value) {
                        $fail('User does not belong to this institution.');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        if ($request->email === $request->user()->email) {
            return $this->sendError('You cannot delete your own account.', [], 422);
        }

        try {
            $user = User::whereEmail($request->email)->first();
            $user->delete();

            return $this->sendResponse([], 'Account deleted successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
        }
    }
}
