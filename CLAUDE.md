# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel 13 web application for institutional management. It's a fresh Laravel installation with Tailwind CSS and Vite for the frontend build system.

**Key Technologies:**
- **Backend:** Laravel 13, PHP 8.3
- **Frontend:** Vite, Tailwind CSS 4
- **Testing:** PHPUnit 12, Mockery
- **Linting/Formatting:** Laravel Pint
- **Database:** SQLite (development), configurable in `.env`

## Development Setup & Commands

### Initial Setup
```bash
composer run setup
```
This single command handles all setup: dependency installation, environment configuration, key generation, migrations, and frontend build.

### Daily Development
```bash
composer run dev
```
Starts all services concurrently with `concurrently`:
- PHP development server (`php artisan serve`)
- Queue listener (`php artisan queue:listen`)
- Log streaming (`php artisan pail`)
- Vite dev server (`npm run dev`)

Services run with color-coded output and auto-cleanup on exit.

### Testing

**Run all tests:**
```bash
composer test
```

**Run specific test suite:**
```bash
php artisan test tests/Unit          # Unit tests only
php artisan test tests/Feature       # Feature tests only
```

**Run a single test file:**
```bash
php artisan test tests/Unit/ExampleTest.php
```

**Run a specific test method:**
```bash
php artisan test tests/Unit/ExampleTest.php --filter=testMethodName
```

**Run with coverage:**
```bash
php artisan test --coverage
```

Test configuration is in `phpunit.xml` (Unit and Feature suites, SQLite in-memory database for isolation).

### Code Quality

**Format code with Pint:**
```bash
composer require laravel/pint --dev  # if not already installed
./vendor/bin/pint
```

## Architecture

### Directory Structure

- **`app/`** - Application code (Models, Controllers, Providers)
  - `Models/` - Eloquent models
  - `Http/Controllers/` - Request handlers
  - `Providers/` - Service providers (AppServiceProvider, etc.)
- **`routes/`** - Route definitions (`web.php` for web routes, `console.php` for CLI commands)
- **`database/`** - Migrations, factories, and seeders
  - `migrations/` - Database schema changes
  - `factories/` - Model factories for testing
  - `seeders/` - Database seeders for data population
- **`resources/`** - Frontend assets
  - `views/` - Blade templates
  - `js/` - JavaScript (ES modules)
  - `css/` - Tailwind CSS configuration and styles
- **`tests/`** - Test suites (Unit and Feature)
- **`config/`** - Configuration files (database, cache, mail, auth, etc.)
- **`storage/`** - Runtime storage (logs, cache, uploads)
- **`public/`** - Web-accessible files and compiled assets
- **`bootstrap/`** - Framework bootstrap files

### Key Design Patterns

**Service Provider Pattern:** Application initialization happens in `app/Providers/AppServiceProvider.php`. Use the `register()` method for container bindings and `boot()` for initialization after all services are registered.

**Eloquent ORM:** All database interactions use Eloquent models (in `app/Models/`). Models handle relationships, scopes, and accessors/mutators.

**Routing:** Web routes are defined in `routes/web.php`. Use route grouping for shared middleware and prefixes.

**Blade Templates:** Use `resources/views/` for all HTML templating. Blade supports components, layouts, and inheritance.

## Frontend Build

**Vite Configuration:** `vite.config.js` configures the build system with `laravel-vite-plugin` and Tailwind CSS.

**Development:** `npm run dev` starts the Vite dev server with hot module replacement.

**Production Build:** `npm run build` creates optimized bundles in `public/build/`.

**Tailwind CSS:** Configured in `vite.config.js` using `@tailwindcss/vite` for JIT compilation. Styles compile from `resources/css/app.css`.

## Environment & Configuration

Environment variables are defined in `.env` (git-ignored) and `.env.example` (committed as a template).

**Critical Variables:**
- `APP_KEY` - Set during setup via `php artisan key:generate`
- `DB_CONNECTION`, `DB_DATABASE` - Database configuration
- `APP_ENV` - Set to `testing` during test runs (in `phpunit.xml`)

## Database & Migrations

**Running migrations:**
```bash
php artisan migrate
```

**Rolling back:**
```bash
php artisan migrate:rollback
```

**Creating migrations:**
```bash
php artisan make:migration create_table_name
```

Migrations are in `database/migrations/` and timestamped for ordering. Always create new migration files rather than editing existing ones.

## Testing Notes

- Tests use an in-memory SQLite database (`phpunit.xml`) to ensure isolation
- Feature tests inherit from `Tests\TestCase` which extends Laravel's `TestCase`
- Use factories (`database/factories/`) to create test data: `UserFactory::new()->create()`
- Tests can access the database via `$this->assertDatabaseHas()` and similar assertions

## Useful Artisan Commands

```bash
php artisan tinker                  # Interactive REPL
php artisan make:model Model        # Create a model
php artisan make:controller ControllerName  # Create a controller
php artisan make:migration create_table_name  # Create a migration
php artisan make:test TestName      # Create a test (Feature by default)
php artisan make:test TestName --unit  # Create a Unit test
php artisan route:list              # List all routes
```
