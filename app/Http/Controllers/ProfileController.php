<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;
use Modules\Institution\Exceptions\InstitutionOwnerTransferRequiredException;
use Modules\Institution\Models\Institution;
use Modules\Institution\Services\InstitutionAdministrationGuard;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        try {
            DB::transaction(function () use ($user): void {
                if ($user->institution_id === null) {
                    $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail()->delete();

                    return;
                }

                $institution = Institution::query()->whereKey($user->institution_id)->lockForUpdate()->firstOrFail();
                $target = $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                app(InstitutionAdministrationGuard::class)->ensureOwnerCanBeChanged($institution, $target, deleting: true);
                $target->delete();
            });
        } catch (InstitutionOwnerTransferRequiredException $e) {
            return Redirect::back()->withErrors(['password' => $e->getMessage()], 'userDeletion');
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
