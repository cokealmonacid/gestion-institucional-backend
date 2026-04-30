<?php

namespace Modules\Institution\Http\Controllers;

use App\Enums\RoleType;
use App\Http\Controllers\Controller;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Institution\Models\Institution;

class InstitutionController extends Controller
{
    public function index()
    {
        $institutions = Institution::orderBy('name')->get();

        return view('institution::index', compact('institutions'));
    }

    public function create()
    {
        return view('institution::create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'boolean'],

            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'unique:users,email'],
            'admin_password' => ['required', 'min:6', 'confirmed'],
        ]);

        DB::transaction(function () use ($validated) {
            $institution = Institution::create([
                'name' => $validated['name'],
                'status' => $validated['status'],
            ]);

            $user = User::create([
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'password' => $validated['admin_password'],
                'institution_id' => $institution->id,
            ]);

            $adminRole = Rol::where('type', RoleType::Admin)->first();

            if ($adminRole) {
                $user->roles()->attach($adminRole->id);
            }
        });

        return redirect()
            ->route('institutions.index')
            ->with('success', 'Institución y administrador creados correctamente');
    }

    public function edit(Institution $institution)
    {
        return view('institution::edit', compact('institution'));
    }

    public function update(Request $request, Institution $institution)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'boolean'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'status.required' => 'Debe seleccionar un estado.',
        ]);

        $institution->update($validated);

        return redirect()
            ->route('institutions.index')
            ->with('success', 'Institución actualizada correctamente.');
    }

    public function destroy(Institution $institution)
    {
        $institution->delete();

        return redirect()
            ->route('institutions.index')
            ->with('success', 'Institución eliminada correctamente.');
    }
}
