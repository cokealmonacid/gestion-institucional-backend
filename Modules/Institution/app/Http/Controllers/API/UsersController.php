<?php

namespace Modules\Institution\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use Illuminate\Http\Request;
use App\Models\User;
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
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error'=> $validator->errors()], 422);
        }

        try {
            $input = $request->all();
            $input['password'] = bcrypt($input['password']);

            $user = User::create($input);
            $user->save();

            return $this->sendResponse([], 'Account registered successfully.');
        } catch (\Exception $e) {
            Log::channel('auth')->error('User register error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return $this->sendError('Something went wrong.', [], 500);
        }
    }
}
