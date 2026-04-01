# Gestión Institucional App

Aplicación web desarrollada con **Laravel 13** para la gestión institucional.

---

## ¿Qué es Laravel?

Laravel es un **framework de PHP** de código abierto, diseñado para construir aplicaciones web modernas de forma rápida, estructurada y elegante. Un framework es un conjunto de herramientas, convenciones y código base que te evita "inventar la rueda" en cada proyecto: manejo de rutas, conexión a bases de datos, autenticación, validaciones, envío de emails y más vienen incluidos o listos para activarse.

Laravel sigue el principio de **"convención sobre configuración"**: si respetas su estructura de carpetas y nombres, el framework sabe cómo conectar las piezas sin que tengas que escribir configuración repetitiva.

---

## Arquitectura MVC

Laravel implementa el patrón **MVC (Modelo–Vista–Controlador)**, que divide la aplicación en tres capas con responsabilidades separadas:

```
Solicitud HTTP
      │
      ▼
┌─────────────┐     ┌─────────────────┐     ┌──────────────┐
│   Rutas     │────▶│  Controlador    │────▶│   Modelo     │
│ routes/     │     │ app/Http/       │     │ app/Models/  │
│ web.php     │     │ Controllers/    │     │              │
└─────────────┘     └────────┬────────┘     └──────┬───────┘
                             │                     │
                             │ datos               │ consulta BD
                             ▼                     ▼
                    ┌─────────────────┐    ┌──────────────┐
                    │      Vista      │    │  Base de     │
                    │ resources/views │    │   Datos      │
                    │  (Blade .php)   │    └──────────────┘
                    └─────────────────┘
```

| Capa | Carpeta | Responsabilidad |
|------|---------|-----------------|
| **Modelo** | `app/Models/` | Representa una tabla de la base de datos. Contiene la lógica de negocio y las relaciones entre tablas. |
| **Vista** | `resources/views/` | Plantillas HTML con sintaxis Blade (`{{ }}`, `@if`, `@foreach`). Solo presenta datos, no los procesa. |
| **Controlador** | `app/Http/Controllers/` | Recibe la petición HTTP, le pide datos al Modelo y los entrega a la Vista. |
| **Ruta** | `routes/web.php` | Define qué URL activa qué Controlador (o cierre de función). |

### Flujo de una petición web típica

1. El navegador solicita `GET /usuarios`
2. `routes/web.php` lo mapea al método `index` del `UsuarioController`
3. El controlador llama a `Usuario::all()` (Modelo Eloquent)
4. Eloquent consulta la base de datos y retorna una colección de objetos
5. El controlador pasa los datos a la vista: `return view('usuarios.index', compact('usuarios'))`
6. La vista Blade renderiza el HTML con los datos y lo devuelve al navegador

### Otros conceptos clave de Laravel

| Concepto | Ubicación | Para qué sirve |
|----------|-----------|----------------|
| **Migrations** | `database/migrations/` | Define la estructura de las tablas en código PHP versionable. Equivale a un "historial de cambios" de la base de datos. |
| **Seeders** | `database/seeders/` | Pobla la base de datos con datos de prueba o iniciales. |
| **Factories** | `database/factories/` | Genera objetos de modelo con datos falsos (para tests o seeds). |
| **Service Providers** | `app/Providers/` | Punto de arranque de la aplicación. Se registran servicios, bindings y configuración al iniciar Laravel. |
| **Middleware** | `app/Http/Middleware/` | Filtros que interceptan peticiones HTTP (ej: verificar si el usuario está autenticado). |
| **Artisan** | CLI (`php artisan`) | Interfaz de comandos de Laravel para generar código, correr migraciones, limpiar caché, etc. |
| **Eloquent ORM** | `app/Models/` | Capa de abstracción de base de datos. Cada modelo representa una tabla; puedes hacer consultas en PHP puro sin escribir SQL directamente. |
| **Blade** | `resources/views/*.blade.php` | Motor de plantillas de Laravel. Permite lógica dentro del HTML de forma limpia. |
| **Vite** | `vite.config.js` | Bundler de assets frontend (CSS, JS). Compila y sirve Tailwind CSS y JavaScript. |

---

## Estructura del Proyecto

```
gestion-institucional-app/
├── app/
│   ├── Http/
│   │   └── Controllers/        # Controladores HTTP
│   ├── Models/                 # Modelos Eloquent (tablas de BD)
│   └── Providers/              # Service Providers
├── bootstrap/                  # Arranque del framework
├── config/                     # Archivos de configuración
├── database/
│   ├── factories/              # Factories para tests/seeds
│   ├── migrations/             # Historial de estructura de BD
│   └── seeders/                # Datos iniciales o de prueba
├── public/                     # Único directorio expuesto al web
│   └── index.php               # Punto de entrada de todas las requests
├── resources/
│   ├── css/                    # Estilos (Tailwind CSS)
│   ├── js/                     # JavaScript
│   └── views/                  # Plantillas Blade (.blade.php)
├── routes/
│   ├── web.php                 # Rutas web (navegador)
│   └── console.php             # Rutas/comandos CLI
├── storage/                    # Logs, caché, archivos subidos
├── tests/
│   ├── Feature/                # Tests de integración (HTTP)
│   └── Unit/                   # Tests unitarios (lógica pura)
├── .env                        # Variables de entorno (no se sube al repo)
├── .env.example                # Plantilla de variables de entorno
├── artisan                     # CLI de Laravel
├── composer.json               # Dependencias PHP
├── package.json                # Dependencias JS
└── vite.config.js              # Configuración del bundler frontend
```

---

## Instalación en Windows

### Requisitos previos

| Herramienta | Versión requerida | Descarga |
|-------------|-------------------|----------|
| **PHP** | 8.3 o superior | [php.net/downloads](https://windows.php.net/download/) |
| **Composer** | Última versión | [getcomposer.org](https://getcomposer.org/download/) |
| **Node.js** | 18 LTS o superior | [nodejs.org](https://nodejs.org/) |
| **MySQL** | 8.0 o superior | [dev.mysql.com](https://dev.mysql.com/downloads/installer/) |
| **Git** | Última versión | [git-scm.com](https://git-scm.com/download/win) |

> **Recomendación:** Para gestionar PHP y MySQL en Windows de forma sencilla, puedes instalar **Laravel Herd** (https://herd.laravel.com/windows) que incluye PHP, Nginx y herramientas de desarrollo en un instalador único. Si prefieres control manual, sigue los pasos a continuación.

---

### Paso 1 — Instalar PHP 8.3

1. Descarga el zip **"VS17 x64 Non Thread Safe"** de https://windows.php.net/download/
2. Extrae el contenido en `C:\php`
3. Agrega `C:\php` al **PATH del sistema**:
   - Busca "Variables de entorno" en el menú Inicio
   - En "Variables del sistema" → selecciona `Path` → `Editar`
   - Agrega `C:\php`
4. Activa extensiones necesarias en `C:\php\php.ini` (copia `php.ini-development` como `php.ini` si no existe):
   ```ini
   extension=curl
   extension=fileinfo
   extension=mbstring
   extension=openssl
   extension=pdo_mysql
   extension=zip
   ```
5. Verifica la instalación:
   ```cmd
   php --version
   # PHP 8.3.x (cli)
   ```

---

### Paso 2 — Instalar Composer

1. Descarga el instalador `Composer-Setup.exe` desde https://getcomposer.org/download/
2. Ejecuta el instalador — detectará automáticamente tu `php.exe`
3. Verifica:
   ```cmd
   composer --version
   # Composer version 2.x.x
   ```

---

### Paso 3 — Instalar Node.js

1. Descarga el instalador LTS desde https://nodejs.org/
2. Instala con las opciones por defecto
3. Verifica:
   ```cmd
   node --version    # v20.x.x
   npm --version     # 10.x.x
   ```

---

### Paso 4 — Instalar MySQL

**Opción A (recomendada): MySQL Installer**
1. Descarga MySQL Installer desde https://dev.mysql.com/downloads/installer/
2. Elige "Developer Default" o al menos instala "MySQL Server" y "MySQL Workbench"
3. Durante la configuración establece la contraseña del usuario `root`

**Opción B: XAMPP**
Descarga XAMPP desde https://www.apachefriends.org — incluye MySQL (MariaDB) y un panel de control visual para iniciar/detener servicios.

---

### Paso 5 — Clonar e instalar el proyecto

Abre una terminal (PowerShell o CMD) y ejecuta:

```powershell
# 1. Clonar el repositorio
git clone <URL-del-repositorio> gestion-institucional-app
cd gestion-institucional-app

# 2. Instalar todas las dependencias (PHP + JS + migraciones + build)
composer run setup
```

El comando `composer run setup` hace automáticamente:
- `composer install` — instala dependencias PHP
- Crea `.env` a partir de `.env.example`
- Genera la `APP_KEY` de encriptación
- Ejecuta las migraciones de base de datos
- `npm install` — instala dependencias JS
- `npm run build` — compila los assets frontend

---

### Paso 6 — Configurar la base de datos

Edita el archivo `.env` con los datos de tu MySQL local:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gestion_institucional_app
DB_USERNAME=root
DB_PASSWORD=tu_password_aqui
```

Crea la base de datos en MySQL (si no existe):
```sql
CREATE DATABASE gestion_institucional_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Luego ejecuta las migraciones:
```powershell
php artisan migrate
```

---

### Paso 7 — Iniciar el servidor de desarrollo

```powershell
composer run dev
```

Este comando inicia en paralelo:
- Servidor PHP en http://localhost:8000
- Servidor Vite (hot-reload para CSS/JS)
- Queue worker (colas de trabajo en segundo plano)
- Pail (visualizador de logs en tiempo real)

Abre tu navegador en **http://localhost:8000**

---

## Comandos Artisan Principales

Artisan es la interfaz de línea de comandos de Laravel. Se invoca con `php artisan`.

```powershell
# Ver todos los comandos disponibles
php artisan list

# Ayuda de un comando específico
php artisan help make:controller
```

### Gestión de la Aplicación

| Comando | Descripción | Ejemplo |
|---------|-------------|---------|
| `php artisan serve` | Inicia el servidor de desarrollo en `localhost:8000` | `php artisan serve --port=8080` |
| `php artisan tinker` | Abre una consola REPL interactiva para ejecutar código PHP/Eloquent | `php artisan tinker` |
| `php artisan key:generate` | Genera la clave de encriptación `APP_KEY` en `.env` | `php artisan key:generate` |

### Rutas

| Comando | Descripción | Ejemplo |
|---------|-------------|---------|
| `php artisan route:list` | Muestra todas las rutas registradas con método, URI, nombre y controlador | `php artisan route:list --path=api` |
| `php artisan route:cache` | Cachea las rutas para mayor rendimiento en producción | `php artisan route:cache` |
| `php artisan route:clear` | Limpia la caché de rutas | `php artisan route:clear` |

### Generación de Código (make:*)

| Comando | Descripción | Ejemplo |
|---------|-------------|---------|
| `php artisan make:model Nombre` | Crea un modelo Eloquent en `app/Models/` | `php artisan make:model Producto` |
| `php artisan make:model Nombre -m` | Crea modelo + migración en un solo comando | `php artisan make:model Producto -m` |
| `php artisan make:model Nombre -mcr` | Crea modelo + migración + controlador resource | `php artisan make:model Producto -mcr` |
| `php artisan make:controller Nombre` | Crea un controlador en `app/Http/Controllers/` | `php artisan make:controller ProductoController` |
| `php artisan make:controller Nombre --resource` | Crea un controlador con los 7 métodos CRUD (index, create, store, show, edit, update, destroy) | `php artisan make:controller ProductoController --resource` |
| `php artisan make:migration nombre` | Crea un archivo de migración en `database/migrations/` | `php artisan make:migration create_productos_table` |
| `php artisan make:seeder Nombre` | Crea un seeder en `database/seeders/` | `php artisan make:seeder ProductoSeeder` |
| `php artisan make:factory Nombre` | Crea una factory en `database/factories/` | `php artisan make:factory ProductoFactory` |
| `php artisan make:request Nombre` | Crea una Form Request para validación | `php artisan make:request StoreProductoRequest` |
| `php artisan make:middleware Nombre` | Crea un middleware en `app/Http/Middleware/` | `php artisan make:middleware VerificarRol` |
| `php artisan make:test Nombre` | Crea un test de tipo Feature en `tests/Feature/` | `php artisan make:test ProductoTest` |
| `php artisan make:test Nombre --unit` | Crea un test unitario en `tests/Unit/` | `php artisan make:test ProductoUnitTest --unit` |

### Migraciones y Base de Datos

| Comando | Descripción | Ejemplo |
|---------|-------------|---------|
| `php artisan migrate` | Ejecuta todas las migraciones pendientes | `php artisan migrate` |
| `php artisan migrate:status` | Muestra el estado de cada migración (ejecutada o pendiente) | `php artisan migrate:status` |
| `php artisan migrate:rollback` | Revierte el último lote de migraciones | `php artisan migrate:rollback` |
| `php artisan migrate:rollback --step=2` | Revierte los últimos 2 lotes | `php artisan migrate:rollback --step=2` |
| `php artisan migrate:fresh` | Borra TODAS las tablas y vuelve a migrar desde cero | `php artisan migrate:fresh` |
| `php artisan migrate:fresh --seed` | Borra, migra y ejecuta los seeders | `php artisan migrate:fresh --seed` |
| `php artisan db:seed` | Ejecuta los seeders sin borrar la BD | `php artisan db:seed` |
| `php artisan db:seed --class=ProductoSeeder` | Ejecuta un seeder específico | `php artisan db:seed --class=ProductoSeeder` |

### Caché y Optimización

| Comando | Descripción | Ejemplo |
|---------|-------------|---------|
| `php artisan cache:clear` | Limpia la caché de la aplicación | `php artisan cache:clear` |
| `php artisan config:clear` | Limpia la caché de configuración | `php artisan config:clear` |
| `php artisan config:cache` | Cachea la configuración para producción | `php artisan config:cache` |
| `php artisan view:clear` | Limpia las vistas Blade compiladas | `php artisan view:clear` |
| `php artisan optimize:clear` | Limpia toda la caché de un golpe (config + rutas + vistas + eventos) | `php artisan optimize:clear` |

### Tests

| Comando | Descripción | Ejemplo |
|---------|-------------|---------|
| `composer test` | Ejecuta todos los tests (limpia config primero) | `composer test` |
| `php artisan test` | Ejecuta todos los tests | `php artisan test` |
| `php artisan test tests/Feature/` | Ejecuta solo los tests de Feature | `php artisan test tests/Feature/` |
| `php artisan test tests/Unit/ExampleTest.php` | Ejecuta un archivo de test específico | `php artisan test tests/Unit/ExampleTest.php` |
| `php artisan test --filter=nombre` | Ejecuta solo los tests que coincidan con el nombre | `php artisan test --filter=test_creates_product` |
| `php artisan test --coverage` | Ejecuta tests con reporte de cobertura de código | `php artisan test --coverage` |

---

## Flujo de Trabajo Típico

### Crear una nueva funcionalidad (ejemplo: Productos)

```powershell
# 1. Crear modelo + migración + controlador resource en un solo comando
php artisan make:model Producto -mcr

# 2. Editar la migración generada en database/migrations/
#    (definir columnas: nombre, precio, descripción, etc.)

# 3. Ejecutar la migración para crear la tabla
php artisan migrate

# 4. Definir las rutas en routes/web.php
#    Route::resource('productos', ProductoController::class);

# 5. Implementar la lógica en app/Http/Controllers/ProductoController.php

# 6. Crear las vistas Blade en resources/views/productos/

# 7. Crear los tests
php artisan make:test ProductoTest

# 8. Ejecutar los tests
php artisan test tests/Feature/ProductoTest.php
```

---

## Solución de Problemas Comunes

| Error | Causa probable | Solución |
|-------|---------------|----------|
| `No application encryption key has been specified` | Falta la `APP_KEY` en `.env` | `php artisan key:generate` |
| `SQLSTATE[HY000] [2002] No such file or directory` | MySQL no está corriendo o las credenciales son incorrectas | Verificar que MySQL esté activo y revisar `.env` |
| `Vite manifest not found` | No se han compilado los assets | `npm run build` o `npm run dev` |
| `Class not found` | Autoload no actualizado | `composer dump-autoload` |
| `TokenMismatchException` | Caché de sesión desactualizada | `php artisan optimize:clear` |
| Permisos en `storage/` o `bootstrap/cache/` | El servidor web no puede escribir en esos directorios | En Windows generalmente no afecta en desarrollo local |

---

## Recursos para Aprender Laravel

- **Documentación oficial (inglés):** https://laravel.com/docs/13.x
- **Laracasts (video-tutoriales):** https://laracasts.com
- **Laravel en español:** https://laraveles.com/docs/
