<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use Illuminate\Http\Request;

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
        ], [
            'name.required' => 'El nombre es obligatorio',
            'status.required' => 'Debe seleccionar un estado',
        ]);

        Institution::create($validated);

        return redirect()
            ->route('institutions.index')
            ->with('success', 'Institución creada correctamente.');
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