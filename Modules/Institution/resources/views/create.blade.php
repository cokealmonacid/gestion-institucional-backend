<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear institución</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-100 min-h-screen">
    <div class="max-w-3xl mx-auto px-6 py-10">
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-slate-800">Crear institución</h1>
            <p class="text-slate-500 mt-1">Formulario básico para alta de tenants.</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <form action="{{ route('institutions.store') }}" method="POST" class="space-y-6">
                @csrf

                <div>
                    <label for="name" class="block text-sm font-medium text-slate-700 mb-2">
                        Nombre
                    </label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="{{ old('name') }}"
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
                        <option value="1" {{ old('status') === '1' ? 'selected' : '' }}>Activa</option>
                        <option value="0" {{ old('status') === '0' ? 'selected' : '' }}>Inactiva</option>
                    </select>
                    @error('status')
                        <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="border-t border-slate-200 pt-6">
                    <h2 class="text-lg font-semibold text-slate-800 mb-4">Administrador inicial</h2>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Usuario</label>
                            <input type="text" name="admin_name" value="{{ old('admin_name') }}"
                                class="w-full border rounded-lg px-3 py-2">
                            @error('admin_name')
                                <p class="text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1">Email</label>
                            <input type="email" name="admin_email" value="{{ old('admin_email') }}"
                                class="w-full border rounded-lg px-3 py-2">
                            @error('admin_email')
                                <p class="text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1">Contraseña</label>
                            <input type="password" name="admin_password"
                                class="w-full border rounded-lg px-3 py-2">
                            @error('admin_password')
                                <p class="text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1">Confirmar contraseña</label>
                            <input type="password" name="admin_password_confirmation"
                                class="w-full border rounded-lg px-3 py-2">
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-lg bg-slate-900 px-5 py-2.5 text-white hover:bg-slate-800"
                    >
                        Guardar
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