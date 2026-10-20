<?php

namespace Modules\Institution\Http\Controllers\API;

use App\Enums\RoleType;
use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Platform\Services\InvitationPublication;
use App\Services\UserAccess\InvitationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Institution\Actions\InstitutionUserMutations;
use Modules\Institution\Actions\InviteInstitutionUser;
use Modules\Institution\Exceptions\InstitutionAccessException;
use Modules\Institution\Exceptions\RoleChangeException;
use Modules\Institution\Http\Resources\UserResource;
use Modules\Institution\Services\RoleChangeImpact;

class UsersController extends BaseController
{
    public function invite(Request $request, InviteInstitutionUser $action, InvitationService $invitations)
    {
        $unexpected = array_values(array_diff(array_keys($request->all()), ['institution_id', 'name', 'email', 'role']));
        if ($unexpected !== []) {
            return $this->sendError('Validation failed.', ['error' => [
                'request' => ['Unexpected properties: '.implode(', ', $unexpected).'.'],
            ]], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::enum(RoleType::class)],
            'institution_id' => ['required', 'uuid'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }
        if (! $this->institutionMatchesActor($request)) {
            return $this->institutionalUserNotFound();
        }

        try {
            [$user, $invitation, $token] = $action->execute($request->user(), $validator->validated(), $invitations);
            $publication = app(InvitationPublication::class)->publish($invitation, $token);
            $queued = $publication['publication_status'] === 'queued';

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user->id,
                    'activation_status' => 'pending_activation',
                    'delivery_status' => $publication['delivery_status'],
                    'expires_at' => $invitation->expires_at->toISOString(),
                ],
                'message' => $publication['publication_status'] === 'unknown' ? 'Resources saved; publication outcome is unknown. Check invitation status before explicit resend.' : ($queued
                    ? 'Account created and invitation queued.'
                    : 'Account created, but the invitation could not be queued. It can be resent.'),
            ], 201);
        } catch (InstitutionAccessException $e) {
            throw $e;
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'INSTITUTION_INACTIVE') {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'INSTITUTION_INACTIVE', 'message' => 'The institution is inactive.'],
                ], 409);
            }

            return response()->json([
                'success' => false,
                'error' => ['code' => 'INSTITUTION_USER_INVITATION_FAILED', 'message' => 'The account could not be created.'],
            ], 500);
        } catch (\Throwable) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INSTITUTION_USER_INVITATION_FAILED', 'message' => 'The account could not be created.'],
            ], 500);
        }
    }

    public function invitationStatus(Request $request, string $user_id)
    {
        $user = User::query()
            ->whereKey($user_id)
            ->where('institution_id', $request->user()->institution_id)
            ->with('invitation')
            ->first();

        if (! $user) {
            return $this->institutionalUserNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->invitationData($user),
            'message' => 'Invitation status retrieved successfully.',
        ]);
    }

    public function resendInvitation(Request $request, string $user_id, InvitationService $invitations)
    {
        try {
            [$invitation, $token] = $invitations->reissue($request->user(), $user_id);
            $publication = app(InvitationPublication::class)->publish($invitation, $token);
            $queued = $publication['publication_status'] === 'queued';

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user_id,
                    'activation_status' => 'pending_activation',
                    'delivery_status' => $publication['delivery_status'],
                    'expires_at' => $invitation->expires_at->toISOString(),
                ],
                'message' => $publication['publication_status'] === 'unknown' ? 'Resources saved; publication outcome is unknown. Check invitation status before explicit resend.' : ($queued
                    ? 'Invitation queued successfully.'
                    : 'The invitation could not be queued. It can be resent.'),
            ], 202);
        } catch (ModelNotFoundException) {
            return $this->institutionalUserNotFound();
        } catch (InstitutionAccessException $e) {
            throw $e;
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'INVITATION_RESEND_THROTTLED') {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'INVITATION_RESEND_THROTTLED', 'message' => 'Wait before resending the invitation.'],
                ], 429);
            }

            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVITATION_NOT_AVAILABLE', 'message' => 'The invitation is not available.'],
            ], 409);
        } catch (\Throwable) {
            return $this->institutionalUserNotFound();
        }
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'institution_id' => ['required', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        if (! $this->institutionMatchesActor($request)) {
            return $this->institutionalUserNotFound();
        }

        $users = User::where('institution_id', $request->user()->institution_id)
            ->with('roles')
            ->paginate($request->input('per_page', 15));

        $users->getCollection()->transform(
            fn (User $user) => (new UserResource($user))->resolve($request)
        );

        return $this->sendResponse($users, 'Users retrieved successfully.');
    }

    public function store(Request $request)
    {
        $this->rejectUnexpected($request, ['name', 'email', 'password', 'password_confirmation', 'institution_id', 'rol']);
        $data = $request->validate([
            'name' => 'required|string|max:255', 'email' => 'bail|required|string|email|unique:users,email',
            'password' => 'required|string|min:8', 'password_confirmation' => 'required|same:password',
            'institution_id' => 'required|uuid', 'rol' => ['required', Rule::enum(RoleType::class)],
        ]);
        if (! $this->institutionMatchesActor($request)) {
            return $this->institutionalUserNotFound();
        }
        app(InstitutionUserMutations::class)->create($request->user(), $data);

        return $this->sendResponse([], 'Account registered successfully.');
    }

    public function update(Request $request)
    {
        $this->rejectUnexpected($request, ['email', 'institution_id', 'name', 'active', 'role', 'role_impact_token', 'confirm_responsibility_removal']);
        $data = $request->validate([
            'email' => 'required|string|email', 'institution_id' => 'required|uuid', 'name' => 'sometimes|required|string|max:255',
            'active' => 'sometimes|required|boolean', 'role' => ['sometimes', 'required', Rule::enum(RoleType::class)],
            'role_impact_token' => 'sometimes|required|string|max:255', 'confirm_responsibility_removal' => 'sometimes|required|boolean',
        ]);
        if (! $this->institutionMatchesActor($request)) {
            return $this->institutionalUserNotFound();
        }
        try {
            app(InstitutionUserMutations::class)->update($request->user(), $data['email'], $data);
        } catch (ModelNotFoundException) {
            return $this->institutionalUserNotFound();
        } catch (RoleChangeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return $this->sendResponse([], 'Account updated successfully.');
    }

    public function roleImpact(Request $request, RoleChangeImpact $impact)
    {
        $this->rejectUnexpected($request, ['email', 'institution_id', 'role']);
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email'],
            'role' => ['required', Rule::enum(RoleType::class)],
            'institution_id' => ['required', 'uuid'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }
        if (! $this->institutionMatchesActor($request)) {
            return $this->institutionalUserNotFound();
        }
        $user = $this->institutionalUserByEmail($request);
        if (! $user) {
            return $this->institutionalUserNotFound();
        }

        return $this->sendResponse(
            $impact->inspect($user, RoleType::from($request->string('role')->toString())),
            'Role change impact retrieved successfully.',
        );
    }

    public function destroy(Request $request)
    {
        $this->rejectUnexpected($request, ['email', 'institution_id']);
        $data = $request->validate(['email' => 'required|string|email', 'institution_id' => 'required|uuid']);
        if (! $this->institutionMatchesActor($request)) {
            return $this->institutionalUserNotFound();
        }
        try {
            app(InstitutionUserMutations::class)->delete($request->user(), $data['email']);
        } catch (ModelNotFoundException) {
            return $this->institutionalUserNotFound();
        }

        return $this->sendResponse([], 'Account deleted successfully.');
    }

    private function rejectUnexpected(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed)) {
            throw ValidationException::withMessages(['request' => ['Unexpected properties.']]);
        }
    }

    private function institutionMatchesActor(Request $request): bool
    {
        return (string) $request->input('institution_id') === (string) $request->user()->institution_id;
    }

    private function institutionalUserByEmail(Request $request): ?User
    {
        return User::where('institution_id', $request->user()->institution_id)
            ->where('email', $request->input('email'))
            ->first();
    }

    private function institutionalUserNotFound()
    {
        return $this->sendError('Institution user not found.', [], 404);
    }

    /** @return array<string, mixed> */
    private function invitationData(User $user): array
    {
        $invitation = $user->invitation;
        $activationStatus = $user->email_verified_at !== null
            ? 'activated'
            : ($invitation ? 'pending_activation' : 'legacy_unverified');

        return [
            'user_id' => $user->id,
            'activation_status' => $activationStatus,
            'delivery_status' => $invitation?->delivery_status,
            'expires_at' => $invitation?->expires_at?->toISOString(),
            'last_sent_at' => $invitation?->last_sent_at?->toISOString(),
        ];
    }
}
