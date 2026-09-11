<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\Document;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use Tests\TestCase;

class DocumentResponsibilityContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_editor_can_assign_change_remove_and_self_assign_with_history(): void
    {
        foreach ([RoleType::Admin, RoleType::Editor] as $role) {
            [$institution, $actor, $document] = $this->context($role);
            $first = User::factory()->for($institution)->create(['name' => 'First Responsible']);
            $second = User::factory()->for($institution)->create(['name' => 'Second Responsible']);
            Sanctum::actingAs($actor);

            $this->patchJson("/api/v1/documents/{$document->id}/responsible", [
                'responsible_user_id' => $first->id, 'expected_revision' => 0,
            ])->assertOk()->assertExactJson($this->success($first, 1));
            $this->patchJson("/api/v1/documents/{$document->id}/responsible", [
                'responsible_user_id' => $second->id, 'expected_revision' => 1,
            ])->assertOk()->assertExactJson($this->success($second, 2));
            $this->patchJson("/api/v1/documents/{$document->id}/responsible", [
                'responsible_user_id' => null, 'expected_revision' => 2,
            ])->assertOk()->assertExactJson($this->success(null, 3));
            $this->patchJson("/api/v1/documents/{$document->id}/responsible", [
                'responsible_user_id' => $actor->id, 'expected_revision' => 3,
            ])->assertOk()->assertExactJson($this->success($actor, 4));

            $this->assertSame(4, DB::table('document_responsible_histories')->where('document_id', $document->id)->count());
            $this->assertDatabaseHas('document_responsible_histories', [
                'document_id' => $document->id, 'new_responsible_user_id' => $first->id,
                'new_responsible_name' => 'First Responsible', 'actor_user_id' => $actor->id, 'revision' => 1,
            ]);
        }
    }

    public function test_authentication_validation_and_document_isolation_use_controlled_responses(): void
    {
        [$institution, $actor, $document] = $this->context(RoleType::Editor);
        $uri = "/api/v1/documents/{$document->id}/responsible";
        $this->patchJson($uri, ['responsible_user_id' => null, 'expected_revision' => 0])
            ->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');

        Sanctum::actingAs($actor);
        $this->patchJson($uri, ['responsible_user_id' => 'invalid', 'expected_revision' => -1, 'extra' => true])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $foreignInstitution = Institution::factory()->create();
        $foreignActor = User::factory()->for($foreignInstitution)->create();
        $foreignNode = Node::factory()->for($foreignInstitution)->create(['active' => true]);
        $foreignDocument = Document::create(['name' => 'Foreign', 'status' => true, 'author_id' => $foreignActor->id, 'institution_id' => $foreignInstitution->id, 'node_id' => $foreignNode->id]);
        foreach ([$foreignDocument->id, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'] as $id) {
            $this->patchJson("/api/v1/documents/{$id}/responsible", ['responsible_user_id' => null, 'expected_revision' => 0])
                ->assertNotFound()->assertExactJson([
                    'success' => false,
                    'error' => ['code' => 'DOCUMENT_NOT_AVAILABLE', 'message' => 'The document is not available.'],
                ]);
            $this->getJson("/api/v1/documents/{$id}/responsible-options?q=user")
                ->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_NOT_AVAILABLE');
        }
    }

    public function test_idempotency_and_optimistic_concurrency_are_enforced(): void
    {
        [$institution, $actor, $document] = $this->context(RoleType::Editor);
        $responsible = User::factory()->for($institution)->create();
        Sanctum::actingAs($actor);
        $uri = "/api/v1/documents/{$document->id}/responsible";

        $this->patchJson($uri, ['responsible_user_id' => $responsible->id, 'expected_revision' => 0])->assertOk();
        $this->patchJson($uri, ['responsible_user_id' => $responsible->id, 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('data.responsibility_revision', 1);
        $this->assertDatabaseCount('document_responsible_histories', 1);

        $this->patchJson($uri, ['responsible_user_id' => null, 'expected_revision' => 0])
            ->assertConflict()->assertExactJson([
                'success' => false,
                'error' => ['code' => 'DOCUMENT_RESPONSIBILITY_CONFLICT', 'message' => 'The document responsibility has changed.'],
            ]);
        $this->assertDatabaseCount('document_responsible_histories', 1);
    }

    public function test_invalid_targets_are_indistinguishable_and_private(): void
    {
        [$institution, $actor, $document] = $this->context(RoleType::Admin);
        $foreign = User::factory()->for(Institution::factory())->create(['email' => 'foreign@example.test']);
        $inactive = User::factory()->for($institution)->inactive()->create(['email' => 'inactive@example.test']);
        $deleted = User::factory()->for($institution)->create(['email' => 'deleted@example.test']);
        $deleted->delete();
        Sanctum::actingAs($actor);

        foreach ([$foreign->id, $inactive->id, $deleted->id, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'] as $id) {
            $response = $this->patchJson("/api/v1/documents/{$document->id}/responsible", [
                'responsible_user_id' => $id, 'expected_revision' => 0,
            ])->assertNotFound()->assertExactJson([
                'success' => false,
                'error' => ['code' => 'DOCUMENT_RESPONSIBLE_NOT_AVAILABLE', 'message' => 'The selected responsible user is not available.'],
            ]);
            $this->assertStringNotContainsString('example.test', $response->getContent());
        }
    }

    public function test_reader_and_roleless_can_read_but_cannot_mutate_or_search(): void
    {
        foreach ([RoleType::Reader, null] as $role) {
            [$institution, $actor, $document] = $this->context($role);
            $responsible = User::factory()->for($institution)->create();
            $document->update(['responsible_user_id' => $responsible->id, 'responsibility_revision' => 1]);
            Sanctum::actingAs($actor);

            $this->getJson("/api/v1/documents/{$document->id}")
                ->assertOk()->assertJsonPath('data.responsible.id', $responsible->id)
                ->assertJsonPath('data.responsible.active', true)
                ->assertJsonPath('data.responsibility_revision', 1)
                ->assertJsonMissingPath('data.responsible.email');
            $this->patchJson("/api/v1/documents/{$document->id}/responsible", ['responsible_user_id' => null, 'expected_revision' => 1])->assertForbidden();
            $this->getJson("/api/v1/documents/{$document->id}/responsible-options?q=test")->assertForbidden();
        }
    }

    public function test_inactive_and_soft_deleted_responsibles_remain_visible_as_inactive(): void
    {
        foreach (['inactive', 'deleted'] as $state) {
            [$institution, $actor, $document] = $this->context(RoleType::Reader);
            $responsible = User::factory()->for($institution)->create();
            $document->update(['responsible_user_id' => $responsible->id, 'responsibility_revision' => 1]);
            $state === 'inactive' ? $responsible->update(['active' => false]) : $responsible->delete();
            Sanctum::actingAs($actor);

            $this->getJson("/api/v1/documents/{$document->id}")
                ->assertOk()->assertJsonPath('data.responsible.id', $responsible->id)
                ->assertJsonPath('data.responsible.active', false);
        }
    }

    public function test_options_are_tenant_scoped_active_ordered_limited_and_exact(): void
    {
        [$institution, $actor, $document] = $this->context(RoleType::Editor);
        foreach (range(1, 12) as $number) {
            User::factory()->for($institution)->create(['name' => sprintf('Match %02d', $number), 'email' => "match{$number}@example.test"]);
        }
        User::factory()->for($institution)->inactive()->create(['name' => 'Match inactive']);
        $deleted = User::factory()->for($institution)->create(['name' => 'Match deleted']);
        $deleted->delete();
        User::factory()->for(Institution::factory())->create(['name' => 'Match foreign']);
        Sanctum::actingAs($actor);

        $data = $this->getJson("/api/v1/documents/{$document->id}/responsible-options?q=MATCH")
            ->assertOk()->assertJsonCount(10, 'data.users')->json('data.users');
        $this->assertSame(['id', 'name', 'email'], array_keys($data[0]));
        $this->assertSame(collect($data)->pluck('name')->sort()->values()->all(), collect($data)->pluck('name')->all());

        $this->getJson("/api/v1/documents/{$document->id}/responsible-options?q=match12@example.test")
            ->assertOk()->assertJsonCount(1, 'data.users');
    }

    public function test_history_failure_rolls_back_the_document_change(): void
    {
        [$institution, $actor, $document] = $this->context(RoleType::Admin);
        $responsible = User::factory()->for($institution)->create();
        Sanctum::actingAs($actor);
        DB::unprepared("CREATE TRIGGER fail_responsibility_history BEFORE INSERT ON document_responsible_histories BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->patchJson("/api/v1/documents/{$document->id}/responsible", [
            'responsible_user_id' => $responsible->id, 'expected_revision' => 0,
        ])->assertStatus(500)->assertExactJson([
            'success' => false,
            'error' => ['code' => 'DOCUMENT_RESPONSIBILITY_FAILED', 'message' => 'The document responsibility could not be updated.'],
        ]);
        $document->refresh();
        $this->assertNull($document->responsible_user_id);
        $this->assertSame(0, $document->responsibility_revision);
        $this->assertDatabaseCount('document_responsible_histories', 0);
    }

    private function context(?RoleType $role): array
    {
        $institution = Institution::factory()->create();
        $actor = User::factory()->for($institution)->create();
        if ($role) {
            $actor->roles()->attach(Rol::firstOrCreate(['type' => $role]));
        }
        $node = Node::factory()->for($institution)->create(['active' => true]);
        $document = Document::create(['name' => 'Policy', 'status' => true, 'author_id' => $actor->id, 'institution_id' => $institution->id, 'node_id' => $node->id]);

        return [$institution, $actor, $document];
    }

    private function success(?User $user, int $revision): array
    {
        return ['success' => true, 'data' => [
            'responsible' => $user ? ['id' => $user->id, 'name' => $user->name, 'active' => true] : null,
            'responsibility_revision' => $revision,
        ], 'message' => 'Document responsibility updated successfully.'];
    }
}
