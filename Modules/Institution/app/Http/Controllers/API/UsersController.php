<?php

namespace Modules\Institution\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use App\Models\RoleUser;
use App\Enums\RoleType;
use App\Models\User;
use App\Models\Rol;
use Validator;

class UsersController extends BaseController
{
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
            'password_confirmation' => 'sometimes|required|same:password'
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
}
