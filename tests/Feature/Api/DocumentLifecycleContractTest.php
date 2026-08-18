<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentVersion;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use Tests\TestCase;

class DocumentLifecycleContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_six_lifecycle_operations_require_authentication(): void
    {
        foreach ([
            ['GET', '/api/v1/documents/document'],
            ['GET', '/api/v1/documents/document/versions'],
            ['POST', '/api/v1/documents/document/versions'],
            ['GET', '/api/v1/documents/document/download'],
            ['GET', '/api/v1/documents/document/versions/version/download'],
            ['PATCH', '/api/v1/documents/document/versions/version/current'],
        ] as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized()
                ->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
        }
    }

    public function test_all_roles_can_read_public_detail_and_versions_without_internal_paths(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        foreach ([RoleType::Admin, RoleType::Editor, RoleType::Reader] as $role) {
            [$institution, $user] = $this->institutionUser($role);
            $document = $this->document($institution, $user);
            $version = $this->version($document, $user, 1, true);
            Sanctum::actingAs($user);

            $detail = $this->getJson("/api/v1/documents/{$document->id}")->assertOk();
            $detail->assertJsonPath('data.current_version.id', $version->id)
                ->assertJsonPath('data.version_count', 1)
                ->assertJsonMissingPath('data.current_version.url');
            $this->assertSame($role !== RoleType::Reader, $detail->json('data.capabilities.can_upload_version'));

            $this->getJson("/api/v1/documents/{$document->id}/versions")
                ->assertOk()->assertJsonPath('data.0.id', $version->id)
                ->assertJsonMissingPath('data.0.url');

            Storage::disk('local')->put('private/file.pdf', 'pdf');
            $this->get("/api/v1/documents/{$document->id}/download")->assertOk();
            $this->get("/api/v1/documents/{$document->id}/versions/{$version->id}/download")->assertOk();
        }
    }

    public function test_admin_and_editor_upload_versions_but_reader_cannot_mutate(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        foreach ([RoleType::Admin, RoleType::Editor] as $role) {
            [$institution, $user] = $this->institutionUser($role);
            $document = $this->document($institution, $user);
            Sanctum::actingAs($user);

            $response = $this->post("/api/v1/documents/{$document->id}/versions", [
                'file' => UploadedFile::fake()->createWithContent('policy.pdf', "%PDF-1.4\n".str_repeat('0', 2048)),
            ])->assertCreated()->assertHeader('Location')
                ->assertJsonPath('data.version_number', 1)
                ->assertJsonPath('data.is_current', true)
                ->assertJsonMissingPath('data.url');
            $this->assertStringEndsWith('/versions/'.$response->json('data.id'), $response->headers->get('Location'));

            $historical = $this->version($document, $user, 2, true, false, 'private/historical.pdf', 'historical.pdf');
            $this->patchJson("/api/v1/documents/{$document->id}/versions/{$historical->id}/current")
                ->assertOk()->assertJsonPath('data.id', $historical->id)
                ->assertJsonPath('data.is_current', true);
        }

        [$institution, $reader] = $this->institutionUser(RoleType::Reader);
        $document = $this->document($institution, $reader);
        $version = $this->version($document, $reader, 1, true);
        Sanctum::actingAs($reader);
        $this->post("/api/v1/documents/{$document->id}/versions", ['file' => UploadedFile::fake()->create('x.pdf', 1, 'application/pdf')])
            ->assertForbidden()->assertJsonPath('error.code', 'DOCUMENT_VERSION_MUTATION_FORBIDDEN');
        $this->patchJson("/api/v1/documents/{$document->id}/versions/{$version->id}/current")
            ->assertForbidden()->assertJsonPath('error.code', 'DOCUMENT_VERSION_MUTATION_FORBIDDEN');
    }

    public function test_new_upload_is_sequential_and_replaces_the_current_version(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        [$institution, $editor] = $this->institutionUser(RoleType::Editor);
        $document = $this->document($institution, $editor);
        $old = $this->version($document, $editor, 1, true);
        Sanctum::actingAs($editor);

        $this->post("/api/v1/documents/{$document->id}/versions", [
            'file' => UploadedFile::fake()->createWithContent('next.txt', str_repeat('version two ', 200)),
        ])->assertCreated()->assertJsonPath('data.version_number', 2);

        $this->assertNull($old->fresh()->current_marker);
        $this->assertFalse($old->fresh()->is_current);
        $this->assertSame(1, DocumentVersion::where('document_id', $document->id)->whereNotNull('current_marker')->count());
    }

    public function test_upload_rejects_empty_unsupported_oversized_and_client_owned_fields(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        [$institution, $admin] = $this->institutionUser(RoleType::Admin);
        $document = $this->document($institution, $admin);
        Sanctum::actingAs($admin);

        foreach ([
            ['file' => UploadedFile::fake()->create('empty.txt', 0, 'text/plain')],
            ['file' => UploadedFile::fake()->create('script.exe', 1, 'application/octet-stream')],
            ['file' => UploadedFile::fake()->create('large.pdf', 25601, 'application/pdf')],
            ['file' => UploadedFile::fake()->create('ok.pdf', 1, 'application/pdf'), 'comment' => 'No'],
            ['file' => UploadedFile::fake()->create('ok.pdf', 1, 'application/pdf'), 'unexpected' => 'No'],
        ] as $payload) {
            $this->post("/api/v1/documents/{$document->id}/versions", $payload)
                ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->assertDatabaseCount('document_versions', 0);
    }

    public function test_location_resolves_to_a_safe_active_version_resource(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        [$institution, $editor] = $this->institutionUser(RoleType::Editor);
        $document = $this->document($institution, $editor);
        Sanctum::actingAs($editor);

        $created = $this->post("/api/v1/documents/{$document->id}/versions", [
            'file' => UploadedFile::fake()->createWithContent('policy.pdf', "%PDF-1.4\n".str_repeat('0', 2048)),
        ])->assertCreated();

        $this->getJson($created->headers->get('Location'))
            ->assertOk()
            ->assertJsonPath('data.id', $created->json('data.id'))
            ->assertJsonMissingPath('data.url');
    }

    public function test_legacy_deactivation_clears_current_and_reactivation_does_not_restore_it(): void
    {
        [$institution, $admin] = $this->institutionUser(RoleType::Admin);
        $document = $this->document($institution, $admin);
        $version = $this->version($document, $admin, 1, true, true);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/documents/{$document->id}/versions/{$version->id}")->assertOk();
        $this->assertFalse($version->fresh()->active);
        $this->assertFalse($version->fresh()->is_current);
        $this->assertNull($version->fresh()->current_marker);

        $this->patchJson("/api/v1/documents/{$document->id}/versions/{$version->id}/activate")->assertOk();
        $this->assertTrue($version->fresh()->active);
        $this->assertFalse($version->fresh()->is_current);
        $this->assertNull($version->fresh()->current_marker);
    }

    public function test_detail_without_versions_is_additive_and_capabilities_follow_the_role(): void
    {
        foreach ([RoleType::Admin, RoleType::Editor, RoleType::Reader] as $role) {
            [$institution, $user] = $this->institutionUser($role);
            $document = $this->document($institution, $user);
            Sanctum::actingAs($user);

            $response = $this->getJson("/api/v1/documents/{$document->id}")->assertOk()
                ->assertJsonPath('data.author.id', $user->id)
                ->assertJsonPath('data.location.id', $document->node_id)
                ->assertJsonPath('data.author_id', $user->id)
                ->assertJsonPath('data.institution_id', $institution->id)
                ->assertJsonPath('data.node_id', $document->node_id)
                ->assertJsonPath('data.current_version', null)
                ->assertJsonPath('data.version_count', 0)
                ->assertJsonPath('data.capabilities.can_download', false);
            $this->assertSame($role !== RoleType::Reader, $response->json('data.capabilities.can_upload_version'));
            $this->assertSame($role !== RoleType::Reader, $response->json('data.capabilities.can_mark_version_current'));
        }
    }

    public function test_inactive_document_missing_node_inactive_node_and_inactive_ancestor_are_unavailable(): void
    {
        [$institution, $reader] = $this->institutionUser(RoleType::Reader);
        Sanctum::actingAs($reader);

        $inactiveDocument = $this->document($institution, $reader);
        $inactiveDocument->update(['status' => false]);
        $missingNode = Document::create(['name' => 'Legacy', 'status' => true, 'author_id' => $reader->id, 'institution_id' => $institution->id, 'node_id' => null]);
        $inactiveNodeDocument = $this->document($institution, $reader);
        $inactiveNodeDocument->node->update(['active' => false]);
        $parent = $inactiveNodeDocument->node;
        $parent->active = false;
        $parent->save();
        $child = new Node;
        $child->id = $child->newUniqueId();
        $child->fill(['name' => 'Child', 'path' => $parent->path.'/'.$child->id, 'depth' => 1, 'order' => 1, 'active' => true, 'institution_id' => $institution->id, 'parent_id' => $parent->id]);
        $child->save();
        $hiddenByAncestor = Document::create(['name' => 'Hidden', 'status' => true, 'author_id' => $reader->id, 'institution_id' => $institution->id, 'node_id' => $child->id]);

        foreach ([$inactiveDocument, $missingNode, $inactiveNodeDocument, $hiddenByAncestor] as $document) {
            $this->getJson("/api/v1/documents/{$document->id}")->assertNotFound()
                ->assertJsonPath('error.code', 'DOCUMENT_NOT_AVAILABLE');
        }
    }

    public function test_version_listing_is_deterministic_and_excludes_other_document_versions(): void
    {
        [$institution, $reader] = $this->institutionUser(RoleType::Reader);
        $document = $this->document($institution, $reader);
        $other = $this->document($institution, $reader);
        $v1 = $this->version($document, $reader, 1, true, false);
        $v3 = $this->version($document, $reader, 3, true, true, 'private/v3', 'v3.pdf');
        $foreignVersion = $this->version($other, $reader, 1, true, true, 'private/other', 'other.pdf');
        Sanctum::actingAs($reader);

        $this->getJson("/api/v1/documents/{$document->id}/versions")
            ->assertOk()->assertJsonPath('data.0.id', $v3->id)->assertJsonPath('data.1.id', $v1->id);
        $this->getJson("/api/v1/documents/{$document->id}/versions/{$foreignVersion->id}")
            ->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_VERSION_NOT_AVAILABLE');
    }

    public function test_inactive_user_is_rejected(): void
    {
        [$institution, $user] = $this->institutionUser(RoleType::Reader);
        $document = $this->document($institution, $user);
        $user->update(['active' => false]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/documents/{$document->id}")->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'This account is inactive.']);
    }

    public function test_storage_and_database_failures_are_controlled_and_compensated(): void
    {
        [$institution, $admin] = $this->institutionUser(RoleType::Admin);
        $document = $this->document($institution, $admin);
        Sanctum::actingAs($admin);
        config(['filesystems.default' => 'missing-disk']);
        $this->post("/api/v1/documents/{$document->id}/versions", [
            'file' => UploadedFile::fake()->createWithContent('policy.pdf', "%PDF-1.4\n".str_repeat('0', 2048)),
        ])->assertStatus(500)->assertJsonPath('error.code', 'DOCUMENT_STORAGE_FAILED');

        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $originalDispatcher = DocumentVersion::getEventDispatcher();
        DocumentVersion::setEventDispatcher(clone $originalDispatcher);
        DocumentVersion::creating(fn () => throw new \RuntimeException('Simulated persistence failure.'));
        try {
            $this->post("/api/v1/documents/{$document->id}/versions", [
                'file' => UploadedFile::fake()->createWithContent('policy.pdf', "%PDF-1.4\n".str_repeat('0', 2048)),
            ])->assertStatus(500)
                ->assertJsonPath('error.code', 'DOCUMENT_VERSION_CREATION_FAILED')
                ->assertJsonMissingPath('error.exception');
            $this->assertSame([], Storage::disk('local')->allFiles());
            $this->assertDatabaseCount('document_versions', 0);
        } finally {
            DocumentVersion::setEventDispatcher($originalDispatcher);
        }
    }

    public function test_laravel_validation_accepts_exactly_25_mib_and_sanitizes_extreme_filename(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        [$institution, $admin] = $this->institutionUser(RoleType::Admin);
        $document = $this->document($institution, $admin);
        Sanctum::actingAs($admin);
        $prefix = "%PDF-1.4\n";
        $content = $prefix.str_repeat('0', 26214400 - strlen($prefix));
        $filename = "..\\quoted\"\r\n-Ñ-".str_repeat('a', 300).'.pdf';

        $response = $this->post("/api/v1/documents/{$document->id}/versions", [
            'file' => UploadedFile::fake()->createWithContent($filename, $content),
        ])->assertCreated();

        $this->assertLessThanOrEqual(240, mb_strlen($response->json('data.filename')));
        $this->assertStringNotContainsString("\r", $response->json('data.filename'));
        $this->assertStringNotContainsString("\n", $response->json('data.filename'));
        $this->assertStringNotContainsString('\\', $response->json('data.filename'));
        $this->assertSame(26214400, $response->json('data.file_size'));
    }

    public function test_inaccessible_documents_and_versions_are_indistinguishable(): void
    {
        [$institution, $reader] = $this->institutionUser(RoleType::Reader);
        [$foreignInstitution, $foreignUser] = $this->institutionUser(RoleType::Admin);
        $foreign = $this->document($foreignInstitution, $foreignUser);
        Sanctum::actingAs($reader);

        foreach (['missing', $foreign->id] as $id) {
            $this->getJson("/api/v1/documents/{$id}")->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_NOT_AVAILABLE');
        }

        $document = $this->document($institution, $reader);
        $inactive = $this->version($document, $reader, 1, false, false);
        $this->getJson("/api/v1/documents/{$document->id}/versions/{$inactive->id}/download")
            ->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_VERSION_NOT_AVAILABLE');
    }

    public function test_upload_uses_a_bounded_physical_key_independent_of_multibyte_visible_name(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        [$institution, $admin] = $this->institutionUser(RoleType::Admin);
        $document = $this->document($institution, $admin);
        Sanctum::actingAs($admin);
        $extreme = "..\\quoted\"\r\n-Ñ-".str_repeat('文', 300).'.PDF';
        $prefix = "%PDF-1.4\n";
        $exact = $prefix.str_repeat('0', 26214400 - strlen($prefix));

        foreach ([
            UploadedFile::fake()->createWithContent($extreme, $prefix.str_repeat('0', 2039)),
            UploadedFile::fake()->createWithContent('short.pdf', $exact),
        ] as $index => $file) {
            $response = $this->post("/api/v1/documents/{$document->id}/versions", ['file' => $file])
                ->assertCreated()
                ->assertJsonMissingPath('data.url');

            $visibleName = $response->json('data.filename');
            $this->assertLessThanOrEqual(240, mb_strlen($visibleName));
            $this->assertStringNotContainsString("\r", $visibleName);
            $this->assertStringNotContainsString("\n", $visibleName);
            $this->assertStringNotContainsString('\\', $visibleName);
            $this->assertStringEndsWith($index === 0 ? '.PDF' : '.pdf', $visibleName);
            $this->assertSame($index === 0 ? 2048 : 26214400, $response->json('data.file_size'));
            $this->assertStringNotContainsString('institutions/', $response->getContent());
            $this->assertStringNotContainsString('private/', $response->getContent());

            $version = DocumentVersion::findOrFail($response->json('data.id'));
            $this->assertLessThanOrEqual(255, strlen($version->url));
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.pdf$/', basename($version->url));
        }
    }

    public function test_downloads_use_safe_headers_and_are_recorded_only_for_existing_files(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        [$institution, $reader] = $this->institutionUser(RoleType::Reader);
        $document = $this->document($institution, $reader);
        $version = $this->version($document, $reader, 1, true, true, 'private/file.pdf', '../policy.pdf');
        Sanctum::actingAs($reader);

        $this->get("/api/v1/documents/{$document->id}/download")
            ->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_FILE_NOT_AVAILABLE');
        $this->assertDatabaseCount('document_downloads', 0);

        Storage::disk('local')->put('private/file.pdf', 'pdf');
        $response = $this->get("/api/v1/documents/{$document->id}/versions/{$version->id}/download")->assertOk();
        $this->assertStringContainsString('policy.pdf', $response->headers->get('content-disposition'));
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertDatabaseCount('document_downloads', 1);
    }

    /** @return array{Institution, User} */
    private function institutionUser(RoleType $role): array
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create();
        $user->roles()->attach(Rol::firstOrCreate(['type' => $role]));

        return [$institution, $user];
    }

    private function document(Institution $institution, User $author): Document
    {
        $node = new Node;
        $node->id = $node->newUniqueId();
        $order = Node::where('institution_id', $institution->id)->whereNull('parent_id')->count() + 1;
        $node->fill(['name' => 'Records '.$order, 'path' => $node->id, 'depth' => 0, 'order' => $order, 'active' => true, 'institution_id' => $institution->id]);
        $node->save();

        return Document::create(['name' => 'Policy', 'status' => true, 'author_id' => $author->id, 'institution_id' => $institution->id, 'node_id' => $node->id]);
    }

    private function version(Document $document, User $author, int $number, bool $active, bool $current = true, string $path = 'private/file.pdf', string $filename = 'policy.pdf'): DocumentVersion
    {
        return DocumentVersion::create(['version_number' => $number, 'url' => $path, 'filename' => $filename, 'mime_type' => 'application/pdf', 'file_size' => 3, 'author_id' => $author->id, 'document_id' => $document->id, 'institution_id' => $document->institution_id, 'node_id' => $document->node_id, 'active' => $active, 'is_current' => $current]);
    }
}
