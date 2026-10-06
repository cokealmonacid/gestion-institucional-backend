<?php

namespace Tests\Feature\Console;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Actions\StoreDocumentVersionAction;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentEvent;
use Modules\Documents\Models\DocumentResponsibleHistory;
use Modules\Documents\Models\DocumentVersion;
use Modules\Documents\Models\DocumentVersionCommentHistory;
use Modules\Documents\Services\DocumentEventRecorder;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use RuntimeException;
use Tests\TestCase;

class UniversidadDelRioDemoCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['filesystems.default' => 'local', 'documents.storage_disk' => 'local']);
        foreach (RoleType::cases() as $type) {
            Rol::create(['type' => $type]);
        }
    }

    public function test_it_loads_the_complete_demo_with_real_downloadable_files_and_coherent_history(): void
    {
        $exitCode = Artisan::call('acervo:demo:universidad-rio', ['--force' => true]);
        $this->assertSame(0, $exitCode, Artisan::output());

        $institution = Institution::where('name', 'Universidad del Río')->firstOrFail();
        $this->assertTrue((bool) $institution->status);
        $this->assertSame(3, User::where('institution_id', $institution->id)->count());
        $this->assertSame(5, Node::where('institution_id', $institution->id)->count());
        $this->assertSame(5, Document::where('institution_id', $institution->id)->count());
        $this->assertSame(6, DocumentVersion::where('institution_id', $institution->id)->count());
        $this->assertSame(14, DocumentEvent::where('institution_id', $institution->id)->count());
        $this->assertSame(1, DocumentResponsibleHistory::count());
        $this->assertSame(2, DocumentVersionCommentHistory::count());

        $admin = User::where('email', 'admin@universidad-del-rio.example')->firstOrFail();
        $editor = User::where('email', 'editor@universidad-del-rio.example')->firstOrFail();
        $reader = User::where('email', 'lector@universidad-del-rio.example')->firstOrFail();
        $this->assertNotNull($admin->email_verified_at);
        $this->assertSame(['admin'], $admin->roles->map(fn (Rol $role): string => $role->type->value)->all());
        $this->assertSame(['editor'], $editor->roles->map(fn (Rol $role): string => $role->type->value)->all());
        $this->assertSame(['reader'], $reader->roles->map(fn (Rol $role): string => $role->type->value)->all());

        $protagonist = Document::where('name', 'Requisitos de ingreso especial 2027')->firstOrFail();
        $this->assertSame($editor->id, $protagonist->responsible_user_id);
        $this->assertSame(1, $protagonist->responsibility_revision);
        $versions = $protagonist->versions()->orderBy('version_number')->get();
        $this->assertFalse($versions[0]->is_current);
        $this->assertTrue($versions[1]->is_current);
        $this->assertSame('Versión inicial de los requisitos de ingreso especial 2027.', $versions[0]->comment);

        foreach (DocumentVersion::all() as $version) {
            Storage::disk('local')->assertExists($version->url);
            $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($version->url));
        }
        $this->actingAs($reader, 'sanctum')
            ->get("/api/v1/documents/{$protagonist->id}/versions/{$versions[1]->id}/download")
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->assertFalse(Node::where('name', 'Orientación a postulantes')->exists());
        $this->assertFalse(Document::where('name', 'Preguntas frecuentes de admisión')->exists());
        $this->assertSame(2, $protagonist->versions()->count());
    }

    public function test_a_second_execution_is_a_no_op_without_duplicate_events(): void
    {
        $this->artisan('acervo:demo:universidad-rio', ['--force' => true])->assertSuccessful();
        $counts = [Document::count(), DocumentVersion::count(), DocumentEvent::count()];

        $this->artisan('acervo:demo:universidad-rio', ['--force' => true])
            ->expectsOutput('Estado: complete')
            ->assertSuccessful();

        $this->assertSame($counts, [Document::count(), DocumentVersion::count(), DocumentEvent::count()]);
    }

    public function test_it_preserves_other_institutions_and_their_content(): void
    {
        $other = Institution::create(['name' => 'Institución existente', 'status' => true]);
        $otherUser = User::factory()->for($other)->create(['email' => 'existing@example.test']);

        $this->artisan('acervo:demo:universidad-rio', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('institutions', ['id' => $other->id, 'name' => 'Institución existente']);
        $this->assertDatabaseHas('users', ['id' => $otherUser->id, 'institution_id' => $other->id]);
    }

    public function test_check_is_read_only_and_reports_absent(): void
    {
        $this->artisan('acervo:demo:universidad-rio', ['--check' => true])
            ->expectsOutput('Estado: absent')
            ->assertSuccessful();

        $this->assertSame(0, Institution::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_partial_modified_and_conflicting_states_are_rejected_without_writes(): void
    {
        $partial = new Institution;
        $partial->id = '755ab137-825b-5f22-a4e5-ab961fea03d3';
        $partial->fill(['name' => 'Universidad del Río', 'status' => true]);
        $partial->save();

        $this->artisan('acervo:demo:universidad-rio', ['--force' => true])->assertFailed();
        $this->assertSame(1, Institution::count());
        $this->assertSame(0, User::count());

        $partial->update(['status' => false]);
        $this->artisan('acervo:demo:universidad-rio', ['--check' => true])
            ->expectsOutput('Estado: modified')
            ->assertFailed();

        $partial->delete();
        Institution::create(['name' => 'Universidad del Río', 'status' => true]);
        $this->artisan('acervo:demo:universidad-rio', ['--check' => true])
            ->expectsOutput('Estado: conflict')
            ->assertFailed();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_later_failure_rolls_back_database_and_compensates_files_from_this_run(): void
    {
        $real = app(StoreDocumentVersionAction::class);
        $calls = 0;
        $this->app->instance(StoreDocumentVersionAction::class, new class($real, $calls) extends StoreDocumentVersionAction
        {
            public function __construct(
                private readonly StoreDocumentVersionAction $delegate,
                private int &$calls,
            ) {}

            public function execute(User $actor, Document $document, UploadedFile $file, DocumentEventRecorder $events): DocumentVersion
            {
                $this->calls++;
                if ($this->calls === 2) {
                    throw new RuntimeException('Fallo de almacenamiento simulado.');
                }

                return $this->delegate->execute($actor, $document, $file, $events);
            }

            public function storageDisk(): string
            {
                return $this->delegate->storageDisk();
            }

            public function deleteStoredFile(string $disk, string $path): void
            {
                $this->delegate->deleteStoredFile($disk, $path);
            }
        });

        $this->artisan('acervo:demo:universidad-rio', ['--force' => true])
            ->expectsOutput('Fallo de almacenamiento simulado.')
            ->assertFailed();

        $this->assertSame(0, Institution::count());
        $this->assertSame(0, Document::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_production_requires_force_but_check_does_not(): void
    {
        $previous = app()->environment();
        app()->detectEnvironment(fn (): string => 'production');

        try {
            $this->artisan('acervo:demo:universidad-rio')->assertFailed();
            $this->artisan('acervo:demo:universidad-rio', ['--check' => true])->assertSuccessful();
        } finally {
            app()->detectEnvironment(fn (): string => $previous);
        }
    }
}
