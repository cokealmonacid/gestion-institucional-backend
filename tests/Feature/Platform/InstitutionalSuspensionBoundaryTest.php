<?php

namespace Tests\Feature\Platform;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use App\Platform\Services\OperationalAdministration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Documents\Actions\StoreDocumentVersionAction;
use Modules\Documents\Models\Document;
use Modules\Documents\Services\DocumentEventRecorder;
use Modules\Institution\Exceptions\InstitutionAccessException;
use Modules\Institution\Models\Institution;
use Modules\Institution\Services\InstitutionalAccess;
use Modules\Nodes\Models\Node;

class InstitutionalSuspensionBoundaryTest extends PlatformTestCase
{
    private function admin(Institution $institution): User
    {
        $user = User::factory()->for($institution)->create();
        $user->roles()->attach(Rol::firstOrCreate(['type' => RoleType::Admin])->id);

        return $user;
    }

    public function test_every_protected_institutional_api_route_denies_suspension_before_controller_work(): void
    {
        $institution = Institution::factory()->create();
        $user = $this->admin($institution);
        $token = $user->createToken('residual fixture')->plainTextToken;
        // Keep a residual credential to test status enforcement independently of token deletion.
        $institution->update(['status' => false]);
        $this->withToken($token);
        $count = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || ! in_array('active', $route->gatherMiddleware(), true)
                || $route->uri() === 'api/v1/auth/logout') {
                continue;
            }
            $uri = '/'.preg_replace('/\{[^}]+\}/', (string) Str::uuid(), $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->json($method, $uri)->assertForbidden()->assertJsonPath('error.code', 'INSTITUTION_SUSPENDED');
                $count++;
            }
        }
        $this->assertGreaterThan(35, $count);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_events', 0);
        $this->assertDatabaseCount('user_invitations', 0);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_missing_and_old_token_epochs_never_become_usable_after_reactivation(): void
    {
        $institution = Institution::factory()->create();
        $user = $this->admin($institution);
        $token = $user->createToken('legacy')->plainTextToken;
        $user->tokens()->update(['institution_auth_epoch' => null]);
        $this->withToken($token)->getJson('/api/v1/user/profile')->assertUnauthorized();
        $user->tokens()->update(['institution_auth_epoch' => 0]);
        $institution->forceFill(['auth_epoch' => 2])->save();
        $this->withToken($token)->getJson('/api/v1/user/profile')->assertUnauthorized();
    }

    public function test_web_login_sessions_and_logout_follow_status_and_user_revision(): void
    {
        $institution = Institution::factory()->create();
        $user = $this->admin($institution);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        Route::middleware(['web', 'auth'])->get('/test/institution-access', fn () => response()->json(['allowed' => true]));
        $this->get('/test/institution-access')->assertOk();
        $this->assertSame(0, session('institution_auth_epoch'));
        $user->forceFill(['access_revision' => 1])->save();
        $this->get('/dashboard')->assertUnauthorized();
        $this->post('/logout')->assertRedirect('/');
        $institution->update(['status' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_array_sessions_do_not_survive_suspension_and_reactivation(): void
    {
        $institution = Institution::factory()->create();
        $user = $this->admin($institution);
        $operator = $this->operator();
        $this->token($operator);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        app(OperationalAdministration::class)->status($operator, $institution->id, false, ['expected_revision' => 0, 'reason' => 'Session test']);
        $this->get('/dashboard')->assertForbidden();
        app(OperationalAdministration::class)->status($operator, $institution->id, true, ['expected_revision' => 1, 'reason' => 'Session test']);
        $this->get('/dashboard')->assertUnauthorized();
        $this->post('/logout')->assertRedirect('/');
    }

    public function test_upload_staging_is_outside_locks_and_denial_compensates_the_file(): void
    {
        Storage::fake('local');
        config(['documents.storage_disk' => 'local']);
        $institution = Institution::factory()->create();
        $user = $this->admin($institution);
        $node = Node::factory()->for($institution)->create(['active' => true]);
        $document = Document::create(['name' => 'Upload fixture', 'institution_id' => $institution->id, 'node_id' => $node->id, 'author_id' => $user->id, 'status' => true]);
        $original = app(StoreDocumentVersionAction::class);
        // Force a state change after file staging but before the transactional persistence check.
        $this->mock(InstitutionalAccess::class)->shouldReceive('lock')->once()->andReturnUsing(function () {
            $this->assertNotEmpty(Storage::disk('local')->allFiles());
            throw new InstitutionAccessException('INSTITUTION_SUSPENDED', 403);
        });
        try {
            $original->execute($user, $document, UploadedFile::fake()->createWithContent('fixture.pdf', '%PDF-fixture'), app(DocumentEventRecorder::class));
            $this->fail('Suspended upload persisted.');
        } catch (InstitutionAccessException $e) {
            $this->assertSame('INSTITUTION_SUSPENDED', $e->errorCode);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertDatabaseCount('document_events', 0);
    }

    public function test_controlled_compatibility_error_rolls_back_its_database_mutation(): void
    {
        $institution = Institution::factory()->create();
        $user = $this->admin($institution);
        Route::middleware(['api', 'auth:sanctum', 'active'])->post('/api/test/controlled-failure', function () use ($institution) {
            $institution->update(['name' => 'Must roll back']);

            return response()->json(['message' => 'Synthetic failure'], 500);
        });
        $before = $institution->name;
        $this->withToken($user->createToken('fixture')->plainTextToken)->postJson('/api/test/controlled-failure')->assertStatus(500);
        $this->assertSame($before, $institution->fresh()->name);
    }
}
