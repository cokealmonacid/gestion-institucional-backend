<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar institución</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-100 min-h-screen">
    <div class="max-w-3xl mx-auto px-6 py-10">
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-slate-800">Editar institución</h1>
            <p class="text-slate-500 mt-1">Actualiza los datos del tenant.</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <form action="{{ route('institutions.update', $institution->id) }}" method="POST" class="space-y-6">
                @csrf
                @method('PUT')

                <div>
                    <label for="name" class="block text-sm font-medium text-slate-700 mb-2">
                        Nombre
                    </label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="{{ old('name', $institution->name) }}"
                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 focus:border-slate-500 focus:outline-none"
                        placeholder="Ingrese el nombre de la institución"
                    >
                    @error('name')
                        <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-slate-700 mb-2">
                        Estado
                    </label>
                    <select
                        id="status"
                        name="status"
                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 focus:border-slate-500 focus:outline-none"
                    >
                        <option value="1" {{ old('status', (string)$institution->status) === '1' ? 'selected' : '' }}>
                            Activa
                        </option>
                        <option value="0" {{ old('status', (string)$institution->status) === '0' ? 'selected' : '' }}>
                            Inactiva
                        </option>
                    </select>
                    @error('status')
                        <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-lg bg-slate-900 px-5 py-2.5 text-white hover:bg-slate-800"
                    >
                        Actualizar
                    </button>

                    <a
                        href="{{ route('institutions.index') }}"
                        class="inline-flex items-center rounded-lg border border-slate-300 px-5 py-2.5 text-slate-700 hover:bg-slate-50"
                    >
                        Volver
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>