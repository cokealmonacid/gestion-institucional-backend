<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentTag;
use Modules\Documents\Models\DocumentVersion;
use Modules\Institution\Models\Institution;
use Modules\Institution\Models\Tag;
use Modules\Nodes\Models\Node;
use Tests\TestCase;

class InstitutionAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_deactivate_and_reactivate_nodes(): void
    {
        $institution = Institution::factory()->create();
        $node = $this->node($institution);

        foreach ([RoleType::Reader, RoleType::Editor] as $role) {
            Sanctum::actingAs($this->user($institution, $role));
            $this->deleteJson("/api/v1/institution/tree-directory/{$node->id}")->assertForbidden();
            $this->patchJson("/api/v1/institution/tree-directory/{$node->id}/activate")->assertForbidden();
            $this->assertSame(1, (int) $node->fresh()->active);
        }

        Sanctum::actingAs($this->user($institution, RoleType::Admin));
        $this->deleteJson("/api/v1/institution/tree-directory/{$node->id}")->assertOk();
        $this->assertSame(0, (int) $node->fresh()->active);
        $this->patchJson("/api/v1/institution/tree-directory/{$node->id}/activate")->assertOk();
        $this->assertSame(1, (int) $node->fresh()->active);
    }

    public function test_placeholder_nodes_resource_mutations_are_also_admin_only(): void
    {
        $institution = Institution::factory()->create();
        $node = $this->node($institution);
        $mutations = [
            ['postJson', '/api/v1/nodes'],
            ['patchJson', "/api/v1/nodes/{$node->id}"],
            ['putJson', "/api/v1/nodes/{$node->id}"],
            ['deleteJson', "/api/v1/nodes/{$node->id}"],
        ];

        foreach ([RoleType::Reader, RoleType::Editor] as $role) {
            Sanctum::actingAs($this->user($institution, $role));

            foreach ($mutations as [$method, $uri]) {
                $this->{$method}($uri)->assertForbidden();
            }
        }

        Sanctum::actingAs($this->user($institution, RoleType::Admin));

        foreach ($mutations as [$method, $uri]) {
            $this->{$method}($uri)->assertOk();
        }

        $this->assertDatabaseHas('nodes', ['id' => $node->id]);
    }

    public function test_reader_cannot_mutate_documents_while_editor_and_admin_can(): void
    {
        foreach ([RoleType::Reader, RoleType::Editor, RoleType::Admin] as $role) {
            $institution = Institution::factory()->create();
            $actor = $this->user($institution, $role);
            $document = $this->document($institution, $actor);
            Sanctum::actingAs($actor);

            $update = $this->patchJson("/api/v1/documents/{$document->id}", ['name' => 'Updated']);
            $delete = $this->deleteJson("/api/v1/documents/{$document->id}");

            if ($role === RoleType::Reader) {
                $update->assertForbidden();
                $delete->assertForbidden();
                $this->patchJson("/api/v1/documents/{$document->id}/activate")->assertForbidden();
                $this->assertSame('Policy', $document->fresh()->name);
                $this->assertTrue($document->fresh()->status);

                continue;
            }

            $update->assertOk();
            $delete->assertOk();
            $this->assertSame('Updated', $document->fresh()->name);
            $this->assertFalse($document->fresh()->status);
            $this->patchJson("/api/v1/documents/{$document->id}/activate")->assertOk();
            $this->assertTrue($document->fresh()->status);
        }
    }

    public function test_reader_cannot_mutate_legacy_versions_while_editor_and_admin_can(): void
    {
        foreach ([RoleType::Reader, RoleType::Editor, RoleType::Admin] as $role) {
            $institution = Institution::factory()->create();
            $actor = $this->user($institution, $role);
            $document = $this->document($institution, $actor);
            $version = $this->version($document, $actor);
            Sanctum::actingAs($actor);

            $deactivate = $this->deleteJson("/api/v1/documents/{$document->id}/versions/{$version->id}");
            if ($role === RoleType::Reader) {
                $deactivate->assertForbidden();
                $this->assertTrue($version->fresh()->active);

                continue;
            }

            $deactivate->assertOk();
            $this->assertFalse($version->fresh()->active);
            $this->patchJson("/api/v1/documents/{$document->id}/versions/{$version->id}/activate")->assertOk();
            $this->assertTrue($version->fresh()->active);
        }
    }

    public function test_document_tag_and_comment_mutations_follow_the_common_role_policy(): void
    {
        foreach ([RoleType::Reader, RoleType::Editor, RoleType::Admin] as $role) {
            $institution = Institution::factory()->create();
            $actor = $this->user($institution, $role);
            $document = $this->document($institution, $actor);
            $version = $this->version($document, $actor);
            $tag = Tag::factory()->create(['institution_id' => $institution->id]);
            $replacementTag = Tag::factory()->create(['institution_id' => $institution->id]);
            Sanctum::actingAs($actor);

            $tagResponse = $this->postJson("/api/v1/institution/document/{$document->id}/tags", [
                'institution_id' => $institution->id,
                'tag_id' => $tag->id,
            ]);
            $commentResponse = $this->patchJson("/api/v1/documents/{$document->id}/versions/{$version->id}/comment", [
                'comment' => 'Reviewed',
            ]);

            if ($role === RoleType::Reader) {
                $tagResponse->assertForbidden();
                $this->patchJson("/api/v1/institution/document/{$document->id}/tags", [
                    'tags_id' => [$replacementTag->id],
                ])->assertForbidden();
                $this->deleteJson("/api/v1/institution/document/{$document->id}/tags", [
                    'tag_id' => $tag->id,
                ])->assertForbidden();
                $commentResponse->assertForbidden();
                $this->assertDatabaseMissing('document_tags', ['document_id' => $document->id]);
                $this->assertNull($version->fresh()->comment);

                continue;
            }

            $tagResponse->assertOk();
            $this->patchJson("/api/v1/institution/document/{$document->id}/tags", [
                'tags_id' => [$replacementTag->id],
            ])->assertOk();
            $this->assertDatabaseMissing('document_tags', ['document_id' => $document->id, 'tag_id' => $tag->id]);
            $this->assertDatabaseHas('document_tags', ['document_id' => $document->id, 'tag_id' => $replacementTag->id]);
            $this->deleteJson("/api/v1/institution/document/{$document->id}/tags", [
                'tag_id' => $replacementTag->id,
            ])->assertOk();
            $commentResponse->assertOk();
            $this->assertDatabaseMissing('document_tags', ['document_id' => $document->id]);
            $this->assertSame('Reviewed', $version->fresh()->comment);
        }
    }

    public function test_cross_tenant_document_tag_and_comment_resources_are_unavailable(): void
    {
        $institution = Institution::factory()->create();
        $editor = $this->user($institution, RoleType::Editor);
        $foreignInstitution = Institution::factory()->create();
        $foreignAuthor = $this->user($foreignInstitution, RoleType::Editor);
        $foreignDocument = $this->document($foreignInstitution, $foreignAuthor);
        $foreignVersion = $this->version($foreignDocument, $foreignAuthor);
        $foreignTag = Tag::factory()->create(['institution_id' => $foreignInstitution->id]);
        DocumentTag::create([
            'document_id' => $foreignDocument->id,
            'tag_id' => $foreignTag->id,
            'assigned_by_id' => $foreignAuthor->id,
        ]);
        Sanctum::actingAs($editor);

        $this->postJson("/api/v1/institution/document/{$foreignDocument->id}/tags", [
            'institution_id' => $institution->id,
            'tag_id' => $foreignTag->id,
        ])->assertNotFound();
        $this->patchJson("/api/v1/documents/{$foreignDocument->id}/versions/{$foreignVersion->id}/comment", [
            'comment' => 'Foreign',
        ])->assertNotFound();

        $this->assertDatabaseHas('document_tags', [
            'document_id' => $foreignDocument->id,
            'tag_id' => $foreignTag->id,
            'assigned_by_id' => $foreignAuthor->id,
        ]);
        $this->assertNull($foreignVersion->fresh()->comment);
    }

    public function test_comment_traceability_reads_remain_available_to_every_role(): void
    {
        foreach (RoleType::cases() as $role) {
            $institution = Institution::factory()->create();
            $actor = $this->user($institution, $role);
            $document = $this->document($institution, $actor);
            $version = $this->version($document, $actor);
            Sanctum::actingAs($actor);

            $this->getJson("/api/v1/documents/{$document->id}/versions/comments")->assertOk();
            $this->getJson("/api/v1/documents/{$document->id}/versions/{$version->id}/comments")->assertOk();
        }
    }

    private function user(Institution $institution, RoleType $role): User
    {
        $user = User::factory()->for($institution)->create();
        $user->roles()->attach(Rol::firstOrCreate(['type' => $role]));

        return $user;
    }

    private function node(Institution $institution): Node
    {
        return Node::factory()->create([
            'institution_id' => $institution->id,
            'parent_id' => null,
            'active' => true,
        ]);
    }

    private function document(Institution $institution, User $author): Document
    {
        $node = $this->node($institution);

        return Document::create([
            'name' => 'Policy',
            'status' => true,
            'author_id' => $author->id,
            'institution_id' => $institution->id,
            'node_id' => $node->id,
        ]);
    }

    private function version(Document $document, User $author): DocumentVersion
    {
        return DocumentVersion::create([
            'version_number' => 1,
            'url' => 'private/file.pdf',
            'filename' => 'policy.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3,
            'author_id' => $author->id,
            'document_id' => $document->id,
            'institution_id' => $document->institution_id,
            'node_id' => $document->node_id,
            'active' => true,
            'is_current' => true,
        ]);
    }
}
