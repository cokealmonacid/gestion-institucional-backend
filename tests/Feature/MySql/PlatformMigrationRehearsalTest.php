<?php

namespace Tests\Feature\MySql;

use App\Models\Rol;
use App\Models\User;
use App\Platform\Services\PlatformReadiness;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Institution\Models\Institution;
use Modules\Institution\Services\MembershipPolicy;
use Tests\TestCase;

class PlatformMigrationRehearsalTest extends TestCase
{
    public function test_guarded_mysql_upgrade_preserves_representative_pre_platform_data(): void
    {
        if (DB::getDriverName() !== 'mysql' || getenv('MYSQL_CONCURRENCY_TESTS') !== '1') {
            $this->markTestSkipped('Dedicated opt-in MySQL database unavailable.');
        }
        require base_path('tests/bootstrap-mysql.php');
        $this->assertSame('acervo_user_access_concurrency_test', DB::connection()->getDatabaseName());
        $this->assertContains(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost']);
        Bus::fake();
        Mail::fake();
        // This suite owns the guarded disposable database. Never run on a working database.
        Schema::dropAllTables();
        Artisan::call('migrate:install');
        $migrator = app('migrator');
        $paths = array_merge($migrator->paths(), [database_path('migrations')]);
        $legacyFiles = array_filter($migrator->getMigrationFiles($paths), fn ($file) => basename($file) < '2026_10_14_000001');
        $migrator->run(array_values($legacyFiles));
        $this->assertFalse(Schema::hasColumn('institutions', 'auth_epoch'));
        $this->assertFalse(Schema::hasTable('platform_operators'));
        $this->seed(RolesSeeder::class);
        $now = now()->toDateTimeString();
        $institution = (string) Str::uuid();
        $disabled = (string) Str::uuid();
        DB::table('institutions')->insert([
            ['id' => $institution, 'name' => 'Synthetic legacy institution', 'status' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $disabled, 'name' => 'Synthetic disabled institution', 'status' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $ids = [];
        foreach (['admin', 'inactive', 'pending', 'deleted'] as $kind) {
            $id = $ids[$kind] = (string) Str::uuid();
            DB::table('users')->insert(['id' => $id, 'institution_id' => $institution, 'name' => 'Synthetic '.$kind,
                'email' => $kind.'@example.test', 'active' => $kind !== 'inactive', 'password' => $kind === 'pending' ? null : Hash::make('Synthetic-rehearsal-password'),
                'email_verified_at' => $kind === 'pending' ? null : $now, 'deleted_at' => $kind === 'deleted' ? $now : null, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($kind === 'admin' ? ['admin', 'editor'] : ['reader'] as $role) {
                DB::table('role_user')->insert(['user_id' => $id, 'role_id' => Rol::where('type', $role)->firstOrFail()->id, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        DB::table('user_invitations')->insert(['id' => (string) Str::uuid(), 'user_id' => $ids['pending'], 'token_hash' => hash('sha256', 'synthetic-expired-proof'), 'generation' => 7, 'delivery_status' => 'failed', 'expires_at' => now()->subDay(), 'last_sent_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('personal_access_tokens')->insert(['tokenable_type' => User::class, 'tokenable_id' => $ids['admin'], 'name' => 'Synthetic legacy credential', 'token' => hash('sha256', 'synthetic-old-credential'), 'abilities' => '["*"]', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('sessions')->insert(['id' => 'synthetic-legacy-session', 'user_id' => $ids['admin'], 'payload' => base64_encode('synthetic'), 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => 'admin@example.test', 'token' => Hash::make('synthetic-recovery-proof'), 'created_at' => $now]);
        $document = (string) Str::uuid();
        DB::table('documents')->insert(['id' => $document, 'name' => 'Synthetic legacy document', 'status' => true, 'institution_id' => $institution, 'author_id' => $ids['admin'], 'responsible_user_id' => $ids['admin'], 'responsibility_revision' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('document_responsible_histories')->insert(['id' => (string) Str::uuid(), 'document_id' => $document, 'new_responsible_user_id' => $ids['admin'], 'actor_user_id' => $ids['admin'], 'new_responsible_name' => 'Synthetic admin', 'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('document_versions')->insert(['id' => (string) Str::uuid(), 'document_id' => $document, 'institution_id' => $institution, 'author_id' => $ids['admin'], 'version_number' => 1, 'url' => 'synthetic/private/reference', 'filename' => 'synthetic.txt', 'mime_type' => 'text/plain', 'file_size' => 10, 'active' => true, 'is_current' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('document_events')->insert(['id' => (string) Str::uuid(), 'document_id' => $document, 'institution_id' => $institution, 'type' => 'created', 'actor_user_id' => $ids['admin'], 'actor_name' => 'Synthetic admin', 'detail' => '{}', 'origin' => 'live', 'source_type' => 'rehearsal', 'source_id' => $document, 'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $snapshots = [];
        foreach (['institutions', 'users', 'roles', 'role_user', 'user_invitations', 'personal_access_tokens', 'sessions', 'password_reset_tokens', 'documents', 'document_versions', 'document_responsible_histories', 'document_events'] as $table) {
            $columns = Schema::getColumnListing($table);
            $order = in_array('id', $columns, true) ? 'id' : 'email';
            $snapshots[$table] = [$columns, $order, DB::table($table)->orderBy($order)->get()->map(fn ($r) => (array) $r)->all()];
        }
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        foreach ($snapshots as $table => [$columns, $order, $rows]) {
            $this->assertSame($rows, DB::table($table)->select($columns)->orderBy($order)->get()->map(fn ($r) => (array) $r)->all(), 'Legacy rows changed: '.$table);
        }
        foreach (DB::table('institutions')->get() as $row) {
            $this->assertSame('legacy_unconfigured', $row->commercial_state);
            $this->assertNull($row->customer_type);
            $this->assertNull($row->customer_name);
            $this->assertNull($row->customer_rut);
            $this->assertSame(0, $row->auth_epoch);
            $this->assertSame(0, $row->operational_revision);
        }
        $this->assertSame(0, DB::table('users')->where('access_revision', '!=', 0)->count());
        $this->assertNull(DB::table('personal_access_tokens')->first()->institution_auth_epoch);
        $this->assertSame(7, DB::table('user_invitations')->first()->generation);
        $this->assertSame(0, DB::table('user_invitations')->first()->institution_auth_epoch);
        foreach (['platform_operators', 'institution_contracts', 'institution_contract_revisions', 'catalog_plans', 'platform_operations', 'administrative_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $usage = DB::transaction(fn () => app(MembershipPolicy::class)->report(Institution::findOrFail($institution)));
        $this->assertSame(['limit' => null, 'used' => 1, 'over_by' => 0], $usage['roles']['admin']);
        $this->assertSame(['limit' => null, 'used' => 1, 'over_by' => 0], $usage['roles']['editor']);
        $this->assertSame(['limit' => null, 'used' => 1, 'over_by' => 0], $usage['roles']['reader']);
        $readiness = app(PlatformReadiness::class)->inspect();
        $this->assertSame([], $readiness['missing_schema']);
        $this->assertNotContains(false, $readiness['unique_constraints']);
        $this->assertSame(1, $readiness['data_findings']['institutions_without_usable_admin']);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        Bus::assertNothingDispatched();
        Mail::assertNothingSent();
    }
}
