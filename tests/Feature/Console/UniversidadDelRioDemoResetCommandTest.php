<?php

namespace Tests\Feature\Console;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use App\Services\Demo\UniversidadDelRioDemoFileCleaner;
use App\Services\Demo\UniversidadDelRioDemoLoader;
use App\Services\Demo\UniversidadDelRioDemoResetter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Actions\CreateDocumentAction;
use Modules\Documents\Actions\StoreDocumentVersionAction;
use Modules\Documents\Actions\UpdateDocumentResponsibilityAction;
use Modules\Documents\Actions\UpdateDocumentVersionNoteAction;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentDownload;
use Modules\Documents\Models\DocumentEvent;
use Modules\Documents\Models\DocumentVersion;
use Modules\Documents\Services\DocumentEventRecorder;
use Modules\Documents\Services\DocumentResponsibilityWriter;
use Modules\Institution\Actions\CreateNodeAction;
use Modules\Institution\Models\Institution;
use Modules\Institution\Models\Tag;
use Modules\Institution\Services\RoleChangeImpact;
use Modules\Nodes\Models\Node;
use RuntimeException;
use Tests\TestCase;

class UniversidadDelRioDemoResetCommandTest extends TestCase
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
        $this->artisan('acervo:demo:universidad-rio', ['--force' => true])->assertSuccessful();
    }

    public function test_reset_preview_is_read_only_and_lists_preserved_accounts_records_files_and_a_token(): void
    {
        $databaseCounts = $this->counts();
        $files = Storage::disk('local')->allFiles();

        $this->artisan('acervo:demo:universidad-rio', ['--reset' => true, '--check' => true])
            ->expectsOutputToContain('Previsualización de restablecimiento (sin escrituras)')
            ->expectsOutputToContain('Cuentas conservadas:')
            ->expectsOutputToContain('Archivos candidatos:')
            ->expectsOutputToContain('Token:')
            ->assertSuccessful();

        $this->assertSame($databaseCounts, $this->counts());
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public function test_invalid_expired_and_stale_tokens_are_rejected_without_writes(): void
    {
        $this->artisan('acervo:demo:universidad-rio', [
            '--reset' => true,
            '--plan-token' => 'invalid',
            '--force' => true,
        ])->assertFailed();
        $baseline = $this->counts();

        CarbonImmutable::setTestNow('2026-10-08 10:00:00');
        $expired = app(UniversidadDelRioDemoResetter::class)->preview()['token'];
        CarbonImmutable::setTestNow('2026-10-08 10:16:00');
        $this->expectExceptionMessage('caducó');
        try {
            app(UniversidadDelRioDemoResetter::class)->reset($expired);
        } finally {
            CarbonImmutable::setTestNow();
            $this->assertSame($baseline, $this->counts());
        }
    }

    public function test_stale_token_detects_a_change_before_any_reset_write(): void
    {
        $resetter = app(UniversidadDelRioDemoResetter::class);
        $token = $resetter->preview()['token'];
        $admin = User::where('email', 'admin@universidad-del-rio.example')->firstOrFail();
        app(CreateNodeAction::class)->execute($admin, null, 'Cambio concurrente');
        $counts = $this->counts();

        try {
            $resetter->reset($token);
            $this->fail('The stale plan should have been rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('obsoleto', $exception->getMessage());
        }

        $this->assertSame($counts, $this->counts());
        $this->assertTrue(Node::where('name', 'Cambio concurrente')->exists());
    }

    public function test_reset_rebuilds_the_initial_scenario_and_preserves_accounts_access_and_other_institutions(): void
    {
        $admin = User::where('email', 'admin@universidad-del-rio.example')->firstOrFail();
        $editor = User::where('email', 'editor@universidad-del-rio.example')->firstOrFail();
        $reader = User::where('email', 'lector@universidad-del-rio.example')->firstOrFail();
        $passwords = [$admin->id => $admin->password, $editor->id => $editor->password, $reader->id => $reader->password];
        $verified = [$admin->id => $admin->email_verified_at, $editor->id => $editor->email_verified_at, $reader->id => $reader->email_verified_at];
        $tokenId = $reader->createToken('demo-access')->accessToken->id;
        $other = Institution::create(['name' => 'Institución ajena', 'status' => true]);
        $otherUser = User::factory()->for($other)->create();

        $protagonist = Document::where('name', 'Requisitos de ingreso especial 2027')->firstOrFail();
        $oldDocumentIds = Document::where('institution_id', UniversidadDelRioDemoLoader::INSTITUTION_ID)->pluck('id')->all();
        $oldPaths = DocumentVersion::where('institution_id', UniversidadDelRioDemoLoader::INSTITUTION_ID)->pluck('url')->all();
        $parent = Node::where('name', 'Admisión 2027')->firstOrFail();
        $orientation = app(CreateNodeAction::class)->execute($admin, $parent->id, 'Orientación a postulantes');
        $faq = app(CreateDocumentAction::class)->execute($editor, $orientation->id, [
            'name' => 'Preguntas frecuentes de admisión',
        ], app(DocumentEventRecorder::class));
        $third = app(StoreDocumentVersionAction::class)->execute(
            $editor,
            $protagonist,
            $this->asset('requisitos-ingreso-especial-2027-v3.pdf'),
            app(DocumentEventRecorder::class),
        );
        app(UpdateDocumentResponsibilityAction::class)->execute(
            $admin,
            $protagonist->id,
            null,
            1,
            app(DocumentEventRecorder::class),
            app(RoleChangeImpact::class),
            app(DocumentResponsibilityWriter::class),
        );
        $first = $protagonist->versions()->where('version_number', 1)->firstOrFail();
        app(UpdateDocumentVersionNoteAction::class)->execute(
            $editor,
            $protagonist->id,
            $first->id,
            'Nota modificada durante la demostración.',
            app(DocumentEventRecorder::class),
        );
        DocumentDownload::create([
            'document_id' => $protagonist->id,
            'document_version_id' => $third->id,
            'user_id' => $reader->id,
        ]);
        $tag = Tag::create(['name' => 'Demo', 'status' => true, 'institution_id' => UniversidadDelRioDemoLoader::INSTITUTION_ID]);
        $faq->tags()->attach($tag->id, ['assigned_by_id' => $admin->id]);

        $resetter = app(UniversidadDelRioDemoResetter::class);
        $result = $resetter->reset($resetter->preview()['token']);

        $this->assertSame('reset_complete', $result['status']);
        $this->assertSame(5, Node::where('institution_id', UniversidadDelRioDemoLoader::INSTITUTION_ID)->count());
        $this->assertSame(5, Document::where('institution_id', UniversidadDelRioDemoLoader::INSTITUTION_ID)->count());
        $this->assertSame(6, DocumentVersion::where('institution_id', UniversidadDelRioDemoLoader::INSTITUTION_ID)->count());
        $this->assertSame(14, DocumentEvent::where('institution_id', UniversidadDelRioDemoLoader::INSTITUTION_ID)->count());
        $this->assertFalse(Node::where('name', 'Orientación a postulantes')->exists());
        $this->assertFalse(Document::where('name', 'Preguntas frecuentes de admisión')->exists());
        $this->assertDatabaseMissing('documents', ['id' => $faq->id]);
        foreach ($oldDocumentIds as $id) {
            $this->assertDatabaseMissing('documents', ['id' => $id]);
        }
        $newPaths = DocumentVersion::where('institution_id', UniversidadDelRioDemoLoader::INSTITUTION_ID)->pluck('url')->all();
        $this->assertSame([], array_intersect($oldPaths, $newPaths));
        foreach ($oldPaths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        foreach ($newPaths as $path) {
            Storage::disk('local')->assertExists($path);
        }
        $rebuilt = Document::where('name', 'Requisitos de ingreso especial 2027')->firstOrFail();
        $this->assertSame($editor->id, $rebuilt->responsible_user_id);
        $this->assertSame(2, $rebuilt->versions()->count());
        $this->assertSame(2, $rebuilt->versions()->whereNotNull('comment')->count());
        foreach ([$admin, $editor, $reader] as $account) {
            $fresh = $account->fresh();
            $this->assertSame($passwords[$account->id], $fresh->password);
            $this->assertEquals($verified[$account->id], $fresh->email_verified_at);
        }
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId, 'tokenable_id' => $reader->id]);
        $this->assertDatabaseHas('institutions', ['id' => $other->id]);
        $this->assertDatabaseHas('users', ['id' => $otherUser->id]);
        $this->assertSame(3, Rol::count());
    }

    public function test_noncanonical_and_shared_files_are_omitted_from_cleanup(): void
    {
        $demoVersions = DocumentVersion::where('institution_id', UniversidadDelRioDemoLoader::INSTITUTION_ID)->take(2)->get();
        $demoVersion = $demoVersions[0];
        $sharedPath = $demoVersions[1]->url;
        $originalPath = $demoVersion->url;
        $legacyPath = 'legacy/demo-shared.pdf';
        Storage::disk('local')->put($legacyPath, Storage::disk('local')->get($originalPath));
        $demoVersion->update(['url' => $legacyPath]);

        $other = Institution::create(['name' => 'Otra institución', 'status' => true]);
        $otherDocument = Document::create([
            'name' => 'Otro documento', 'status' => true, 'institution_id' => $other->id,
        ]);
        $otherVersion = new DocumentVersion;
        $otherVersion->fill([
            'version_number' => 1, 'url' => $legacyPath, 'filename' => 'shared.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 10, 'document_id' => $otherDocument->id,
            'institution_id' => $other->id, 'active' => true, 'is_current' => true,
        ]);
        $otherVersion->save();
        $sharedVersion = new DocumentVersion;
        $sharedVersion->fill([
            'version_number' => 2, 'url' => $sharedPath, 'filename' => 'canonical-shared.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 10, 'document_id' => $otherDocument->id,
            'institution_id' => $other->id, 'active' => true, 'is_current' => false,
        ]);
        $sharedVersion->save();

        $resetter = app(UniversidadDelRioDemoResetter::class);
        $preview = $resetter->preview();
        $candidate = collect($preview['files'])->firstWhere('path', $legacyPath);
        $this->assertSame('outside_expected_prefix', $candidate['reason']);
        $sharedCandidate = collect($preview['files'])->firstWhere('path', $sharedPath);
        $this->assertSame('shared', $sharedCandidate['reason']);
        $result = $resetter->reset($preview['token']);

        $this->assertContains(['path' => $legacyPath, 'reason' => 'outside_expected_prefix'], $result['omitted_files']);
        $this->assertContains(['path' => $sharedPath, 'reason' => 'shared'], $result['omitted_files']);
        Storage::disk('local')->assertExists($legacyPath);
        Storage::disk('local')->assertExists($sharedPath);
        $this->assertDatabaseHas('document_versions', ['id' => $otherVersion->id, 'url' => $legacyPath]);
        $this->assertDatabaseHas('document_versions', ['id' => $sharedVersion->id, 'url' => $sharedPath]);
    }

    public function test_incompatible_account_roles_block_preview(): void
    {
        User::where('email', 'lector@universidad-del-rio.example')->firstOrFail()->roles()->sync([
            Rol::where('type', RoleType::Editor)->firstOrFail()->id,
        ]);
        $this->expectExceptionMessage('incompatible');
        app(UniversidadDelRioDemoResetter::class)->preview();
    }

    public function test_missing_accounts_block_preview(): void
    {
        User::where('email', 'lector@universidad-del-rio.example')->firstOrFail()->delete();
        $this->expectExceptionMessage('exactamente las tres cuentas');
        app(UniversidadDelRioDemoResetter::class)->preview();
    }

    public function test_additional_accounts_block_preview(): void
    {
        User::factory()->create([
            'institution_id' => UniversidadDelRioDemoLoader::INSTITUTION_ID,
            'email' => 'extra@universidad-del-rio.example',
        ]);
        $this->expectExceptionMessage('exactamente las tres cuentas');
        app(UniversidadDelRioDemoResetter::class)->preview();
    }

    public function test_outer_transaction_failure_restores_old_data_and_compensates_new_files(): void
    {
        $oldIds = Document::pluck('id')->all();
        $oldFiles = Storage::disk('local')->allFiles();
        $realLoader = app(UniversidadDelRioDemoLoader::class);
        $this->app->instance(UniversidadDelRioDemoLoader::class, new class($realLoader) extends UniversidadDelRioDemoLoader
        {
            public function __construct(private readonly UniversidadDelRioDemoLoader $delegate) {}

            public function populate(array $users, array &$createdFiles, array $forbiddenPaths = []): void
            {
                $this->delegate->populate($users, $createdFiles, $forbiddenPaths);
                throw new RuntimeException('Fallo exterior simulado.');
            }

            public function inspect(): array
            {
                return $this->delegate->inspect();
            }
        });
        $resetter = app(UniversidadDelRioDemoResetter::class);
        $token = $resetter->preview()['token'];

        try {
            $resetter->reset($token);
            $this->fail('The simulated outer failure should be reported.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Fallo exterior simulado', $exception->getMessage());
        }

        $this->assertEqualsCanonicalizing($oldIds, Document::pluck('id')->all());
        $this->assertEqualsCanonicalizing($oldFiles, Storage::disk('local')->allFiles());
    }

    public function test_post_commit_cleanup_failure_keeps_rebuilt_scenario_and_reports_pending_keys(): void
    {
        $this->app->instance(UniversidadDelRioDemoFileCleaner::class, new class extends UniversidadDelRioDemoFileCleaner
        {
            public function deleteExact(string $diskName, string $path): bool
            {
                return false;
            }
        });
        $resetter = app(UniversidadDelRioDemoResetter::class);
        $preview = $resetter->preview();
        $result = $resetter->reset($preview['token']);

        $this->assertSame('reset_complete_cleanup_incomplete', $result['status']);
        $this->assertNotEmpty($result['operation_id']);
        $this->assertCount(6, $result['pending_files']);
        $this->assertSame('complete', app(UniversidadDelRioDemoLoader::class)->inspect()['status']);
        foreach ($result['pending_files'] as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_ambiguous_reset_options_are_rejected(): void
    {
        $this->artisan('acervo:demo:universidad-rio', ['--reset' => true])->assertFailed();
        $this->artisan('acervo:demo:universidad-rio', [
            '--reset' => true, '--check' => true, '--force' => true,
        ])->assertFailed();
        $this->artisan('acervo:demo:universidad-rio', ['--plan-token' => 'x'])->assertFailed();
    }

    public function test_reviewed_reset_plan_can_be_executed_through_the_command(): void
    {
        $token = app(UniversidadDelRioDemoResetter::class)->preview()['token'];

        $this->artisan('acervo:demo:universidad-rio', [
            '--reset' => true,
            '--plan-token' => $token,
            '--force' => true,
        ])->expectsOutputToContain('Restablecimiento completado.')
            ->expectsOutputToContain('Operación:')
            ->assertSuccessful();

        $this->assertSame('complete', app(UniversidadDelRioDemoLoader::class)->inspect()['status']);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'institutions' => Institution::count(),
            'users' => User::count(),
            'nodes' => Node::count(),
            'documents' => Document::count(),
            'versions' => DocumentVersion::count(),
            'events' => DocumentEvent::count(),
        ];
    }

    private function asset(string $filename): UploadedFile
    {
        return new UploadedFile(
            resource_path('demo/universidad-del-rio/'.$filename),
            $filename,
            'application/pdf',
            null,
            true,
        );
    }
}
