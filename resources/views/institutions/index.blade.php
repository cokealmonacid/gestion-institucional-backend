<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instituciones</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-100 min-h-screen">
    
    <div class="max-w-5xl mx-auto px-6 py-10">
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-slate-800">Listado de Instituciones</h1>
           
        </div>
        <div class="mb-4 flex justify-end">
            <a href="{{ route('institutions.create') }}"
            class="inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-white hover:bg-slate-800">
                Nueva institución
            </a>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            @if(session('success'))
                <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-700">
                    {{ session('success') }}
                </div>
            @endif
            <table class="min-w-full">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="text-left px-4 py-3 text-sm font-semibold text-slate-700">ID</th>
                        <th class="text-left px-4 py-3 text-sm font-semibold text-slate-700">Nombre</th>
                        <th class="text-left px-4 py-3 text-sm font-semibold text-slate-700">Estado</th>
                        <th class="text-left px-4 py-3 text-sm font-semibold text-slate-700">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @foreach($institutions as $inst)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-sm text-slate-700">{{ $inst['id'] }}</td>
                            <td class="px-4 py-3 text-sm text-slate-900 font-medium">{{ $inst['name'] }}</td>
                            <td class="px-4 py-3 text-sm">
                                @if($inst['status'])
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                                        Activo
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-700">
                                        Inactivo
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <a href="{{ route('institutions.edit', $inst->id) }}"
                                class="inline-flex items-center rounded-lg bg-amber-500 px-3 py-1.5 text-white hover:bg-amber-600">
                                    Editar
                                </a>
                            </td>
                            <td>
                                <form action="{{ route('institutions.destroy', $inst->id) }}" method="POST" onsubmit="return confirm('¿Seguro que deseas eliminar esta institución?')">
                                    @csrf
                                    @method('DELETE')

                                    <button
                                        type="submit"
                                        class="inline-flex items-center rounded-lg bg-rose-500 px-3 py-1.5 text-white hover:bg-rose-600">
                                        Eliminar
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>