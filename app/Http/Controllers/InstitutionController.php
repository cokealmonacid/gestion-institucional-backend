<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Rol;
use App\Enums\RoleType;
use Illuminate\Support\Facades\DB;

class InstitutionController extends Controller
{
    public function index()
    {
        $institutions = Institution::orderBy('name')->get();

        return view('institutions.index', compact('institutions'));
    }

    public function create()
    {
        return view('institutions.create');
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

        // 1. Crear institución
        $institution = \App\Models\Institution::create([
            'name' => $validated['name'],
            'status' => $validated['status'],
        ]);

        // 2. Crear usuario admin
        $user = User::create([
            'name' => $validated['admin_name'],
            'email' => $validated['admin_email'],
            'password' => $validated['admin_password'],
            'institution_id' => $institution->id,
        ]);

        // 3. Obtener rol admin
        $adminRole = Rol::where('type', RoleType::Admin)->first();

        // 4. Asignar rol
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
        return view('institutions.edit', compact('institution'));
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