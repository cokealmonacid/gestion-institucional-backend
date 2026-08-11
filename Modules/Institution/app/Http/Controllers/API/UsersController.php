<?php

namespace Modules\Institution\Http\Controllers\API;

use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\BaseController;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use App\Models\RoleUser;
use App\Enums\RoleType;
use App\Models\User;
use App\Models\Rol;

class UsersController extends BaseController
{
    public function index(Request $request)
    {
        $users = User::where('institution_id', $request->user()->institution_id)
            ->paginate($request->input('per_page', 15));

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
            return $this->sendError('Validation failed.', ['error'=> $validator->errors()], 422);
        }

        try {
            $input = $request->all();
            $rol = Rol::whereType($request->rol)->first();
            $input['password'] = bcrypt($input['password']);

            $user = User::create($input);
            $user->save();

            RoleUser::create([
                'user_id' => $user->id,
                'role_id' => $rol->id
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
            'password' => 'sometimes|required|min:8',
            'password_confirmation' => 'sometimes|required|same:password',
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
            return $this->sendError('Validation failed.', ['error'=> $validator->errors()], 422);
        }

        try {
            $user = User::whereEmail($request->email)->first();
            $input = $request->all();

            if (isset($input['password'])) {
                $input['password'] = bcrypt($input['password']);
            }

            $user->update($input);

            return $this->sendResponse([], 'Account updated successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
        }
    }

    public function updateRole(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|exists:users,email',
            'role' => ['required', Rule::enum(RoleType::class), 'different:old_role'],
            'old_role' => ['required', Rule::enum(RoleType::class)],
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
            return $this->sendError('Validation failed.', ['error'=> $validator->errors()], 422);
        }

        try {
            $user = User::whereEmail($request->email)->first();
            $rol = Rol::whereType($request->role)->first();

            RoleUser::where('user_id', $user->id)
                ->where('role_id', Rol::whereType($request->old_role)->first()->id)
                ->delete();

            RoleUser::firstOrCreate([
                'user_id' => $user->id,
                'role_id' => $rol->id,
            ]);

            return $this->sendResponse([], 'Role updated successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
        }
    }
}
