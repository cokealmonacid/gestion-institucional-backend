<?php

namespace Modules\Institution\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\AuthSessionIssuer;
use Modules\Institution\Actions\CreateInstitutionAction;
use Modules\Institution\Exceptions\InstitutionCreationException;
use Modules\Institution\Http\Requests\CreateInstitutionRequest;

class OnboardingInstitutionController extends Controller
{
    public function store(
        CreateInstitutionRequest $request,
        CreateInstitutionAction $createInstitution,
        AuthSessionIssuer $sessionIssuer,
    ) {
        $currentToken = $request->user()->currentAccessToken();

        try {
            $result = $createInstitution->execute($request->user(), $request->validated('name'));
        } catch (InstitutionCreationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->status);
        }

        $user = $request->user()->fresh(['institution:id,name', 'roles:id,type']);
        $session = $sessionIssuer->exchange($user, $request, $currentToken);

        return response()->json([
            'success' => true,
            'data' => $session,
            'message' => $result['created'] ? 'Institution created successfully.' : 'Institution already created.',
        ], $result['created'] ? 201 : 200);
    }
}
