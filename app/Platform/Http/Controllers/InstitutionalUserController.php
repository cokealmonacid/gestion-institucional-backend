<?php

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Http\PlatformInput;
use App\Platform\Services\InstitutionalUserAdministration;
use Illuminate\Http\Request;
use Modules\Institution\Exceptions\RoleChangeException;

class InstitutionalUserController extends Controller
{
    public function handle(Request $request, string $id, string $userId, string $action, InstitutionalUserAdministration $service)
    {
        $rules = $action === 'impact' ? ['role' => 'required|in:admin,editor,reader'] : [
            'expected_user_revision' => 'required|integer|min:0', 'reason' => 'required|string|max:1000', 'confirmation' => 'required|accepted',
        ];
        if ($action === 'role') {
            $rules += ['role' => 'required|in:admin,editor,reader', 'confirm_responsibility_removal' => 'sometimes|boolean', 'role_impact_token' => 'sometimes|string|max:200'];
        }
        if ($action === 'access') {
            $rules += ['active' => 'required|boolean'];
        }
        $data = PlatformInput::validate($request, $rules);

        try {
            return response()->json(['success' => true, 'data' => $service->execute($request->user(), $id, $userId, $action, $data), 'message' => 'Institutional access request completed.']);
        } catch (RoleChangeException $error) {
            return ApiResponse::error($error->errorCode, $error->getMessage(), $error->status);
        }
    }
}
