<?php

namespace Modules\Institution\Http\Controllers\API;

use App\Enums\RoleType;
use App\Http\Controllers\BaseController;
use App\Models\Rol;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Documents\Services\DocumentEventRecorder;
use Modules\Documents\Services\DocumentResponsibilityWriter;
use Modules\Institution\Exceptions\RoleChangeException;
use Modules\Institution\Http\Resources\UserResource;
use Modules\Institution\Models\Institution;
use Modules\Institution\Services\RoleChangeImpact;

class UsersController extends BaseController
{
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
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'bail|required|string|email|unique:users,email',
            'password' => 'required|min:8',
            'password_confirmation' => 'required|same:password',
            'institution_id' => ['required', 'uuid'],
            'rol' => ['required', Rule::enum(RoleType::class)],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed.', ['error' => $validator->errors()], 422);
        }

        if (! $this->institutionMatchesActor($request)) {
            return $this->institutionalUserNotFound();
        }

        try {
            $input = $request->all();
            $input['institution_id'] = $request->user()->institution_id;
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
            'email' => 'required|string|email',
            'name' => 'sometimes|required|string|max:255',
            'active' => 'sometimes|required|boolean',
            'role' => ['sometimes', 'required', Rule::enum(RoleType::class)],
            'role_impact_token' => ['sometimes', 'required', 'string', 'max:255'],
            'confirm_responsibility_removal' => ['sometimes', 'required', 'boolean'],
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

        try {
            $updated = DB::transaction(function () use ($request, $user): bool {
                // Serializes self-demotions within one institution so two admins cannot
                // both observe the other as an administrator and remove that access.
                Institution::query()
                    ->whereKey($request->user()->institution_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $target = User::query()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($this->isBlockedSelfAdminDemotion($request, $target)) {
                    return false;
                }

                $requestedRole = $request->filled('role') ? RoleType::from($request->string('role')->toString()) : null;
                $impact = app(RoleChangeImpact::class);
                $documents = collect();

                if ($requestedRole !== null && $impact->losesEligibility($target, $requestedRole)) {
                    $documents = $impact->affectedDocuments($target, lock: true);
                    $token = $request->input('role_impact_token');
                    if ($request->boolean('confirm_responsibility_removal') !== true || ! is_string($token)) {
                        throw new RoleChangeException(
                            'ROLE_CHANGE_IMPACT_CONFIRMATION_REQUIRED',
                            'Review and confirm the responsibility impact before changing this role.',
                            409,
                        );
                    }
                    if (! $impact->tokenMatches($target, $requestedRole, $documents, $token)) {
                        throw new RoleChangeException(
                            'ROLE_CHANGE_IMPACT_STALE',
                            'The responsibility impact has changed. Review it again before confirming.',
                            409,
                        );
                    }
                }

                $input = $request->except([
                    'role', 'email', 'institution_id', 'role_impact_token', 'confirm_responsibility_removal',
                ]);
                $target->update($input);

                if ($request->filled('role')) {
                    $rol = Rol::whereType($request->role)->firstOrFail();

                    RoleUser::where('user_id', $target->id)->delete();

                    RoleUser::firstOrCreate([
                        'user_id' => $target->id,
                        'role_id' => $rol->id,
                    ]);
                }

                if ($documents->isNotEmpty()) {
                    $events = app(DocumentEventRecorder::class);
                    $writer = app(DocumentResponsibilityWriter::class);
                    foreach ($documents as $document) {
                        $writer->write(
                            $document,
                            null,
                            $request->user(),
                            $events,
                            DocumentResponsibilityWriter::ROLE_CHANGE_REASON,
                        );
                    }
                }

                return true;
            });

            if (! $updated) {
                return response()->json([
                    'success' => false,
                    'message' => 'The institution must retain another administrator.',
                    'code' => 'INSTITUTION_REQUIRES_ANOTHER_ADMIN',
                ], 409);
            }

            return $this->sendResponse([], 'Account updated successfully.');
        } catch (RoleChangeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], $e->status);
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
        }
    }

    public function roleImpact(Request $request, RoleChangeImpact $impact)
    {
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
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
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

        if ($request->email === $request->user()->email) {
            return $this->sendError('You cannot delete your own account.', [], 422);
        }

        try {
            $user->delete();

            return $this->sendResponse([], 'Account deleted successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong.', [], 500);
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

    private function isBlockedSelfAdminDemotion(Request $request, User $target): bool
    {
        if (
            $target->id !== $request->user()->id
            || $request->input('role') === RoleType::Admin->value
            || ! $target->roles()->where('type', RoleType::Admin)->exists()
        ) {
            return false;
        }

        // Inactive accounts intentionally count: the business rule requires another
        // admin account, not necessarily another admin currently able to sign in.
        return ! User::query()
            ->where('institution_id', $target->institution_id)
            ->where($target->getKeyName(), '!=', $target->getKey())
            ->whereHas('roles', fn ($query) => $query->where('type', RoleType::Admin))
            ->exists();
    }

    private function institutionalUserNotFound()
    {
        return $this->sendError('Institution user not found.', [], 404);
    }
}
