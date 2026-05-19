<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class UsersController extends BaseController
{
    public function profile(Request $request)
    {
        return new UserResource($request->user());
    }
}
