<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentEvent;
use Modules\Documents\Models\DocumentVersion;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use Tests\TestCase;

class DocumentHistoryContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_business_change_records_exactly_one_expected_event_and_no_ops_record_none(): void
    {
        Storage::fake('local');
        [$institution, $actor, $node] = $this->context(RoleType::Admin);
        Sanctum::actingAs($actor);

        $documentId = $this->postJson("/api/v1/institution/tree-directory/{$node->id}/documents", ['name' => 'Policy'])
            ->assertCreated()->json('data.id');
        $firstId = $this->post("/api/v1/documents/{$documentId}/versions", [
            'file' => UploadedFile::fake()->createWithContent('one.pdf', "%PDF-1.4\n".str_repeat('0', 2048)),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $secondId = $this->post("/api/v1/documents/{$documentId}/versions", [
            'file' => UploadedFile::fake()->createWithContent('two.pdf', "%PDF-1.4\n".str_repeat('1', 2048)),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/documents/{$documentId}/versions/{$firstId}/current")->assertOk();
        $responsibleA = User::factory()->for($institution)->create(['name' => 'Ana']);
        $responsibleB = User::factory()->for($institution)->create(['name' => 'Bea']);
        $responsibilityUri = "/api/v1/documents/{$documentId}/responsible";
        $this->patchJson($responsibilityUri, ['responsible_user_id' => $responsibleA->id, 'expected_revision' => 0])->assertOk();
        $this->patchJson($responsibilityUri, ['responsible_user_id' => $responsibleB->id, 'expected_revision' => 1])->assertOk();
        $this->patchJson($responsibilityUri, ['responsible_user_id' => null, 'expected_revision' => 2])->assertOk();

        $expected = [
            'document.created', 'document.version_uploaded', 'document.version_uploaded',
            'document.current_version_changed', 'document.responsible_assigned',
            'document.responsible_changed', 'document.responsible_removed',
        ];
        $actual = DocumentEvent::where('document_id', $documentId)->get()->map(fn (DocumentEvent $event) => $event->type->value)->all();
        $this->assertEqualsCanonicalizing($expected, $actual);
        $this->assertSame(3, DB::table('document_responsible_histories')->where('document_id', $documentId)->count());
        $this->assertDatabaseHas('document_events', ['document_id' => $documentId, 'version_id' => $secondId, 'type' => 'document.version_uploaded']);
        $upload = DocumentEvent::where('version_id', $secondId)->firstOrFail();
        $this->assertTrue($upload->detail['became_current']);

        $count = DocumentEvent::where('document_id', $documentId)->count();
        $this->patchJson($responsibilityUri, ['responsible_user_id' => null, 'expected_revision' => 3])->assertOk();
        $this->patchJson("/api/v1/documents/{$documentId}/versions/{$firstId}/current")->assertOk();
        $this->assertSame($count, DocumentEvent::where('document_id', $documentId)->count());
    }

    public function test_history_is_cursor_paginated_without_gaps_for_equal_timestamps_and_has_bounded_queries(): void
    {
        [$institution, $actor, , $document] = $this->context(RoleType::Reader, true);
        Sanctum::actingAs($actor);
        $time = now()->startOfSecond();
        foreach (range(1, 45) as $number) {
            DocumentEvent::create([
                'document_id' => $document->id, 'institution_id' => $institution->id,
                'type' => 'document.created', 'actor_user_id' => $actor->id,
                'actor_name' => $actor->name, 'detail' => [], 'origin' => 'recorded',
                'source_type' => 'test', 'source_id' => (string) $number, 'occurred_at' => $time,
            ]);
        }

        $seen = [];
        $cursor = null;
        do {
            $query = $cursor ? '?limit=20&cursor='.urlencode($cursor) : '?limit=20';
            $response = $this->getJson("/api/v1/documents/{$document->id}/history{$query}")->assertOk();
            array_push($seen, ...array_column($response->json('data'), 'id'));
            $cursor = $response->json('meta.next_cursor');
        } while ($cursor !== null);

        $this->assertCount(45, $seen);
        $this->assertCount(45, array_unique($seen));

        DB::enableQueryLog();
        $this->getJson("/api/v1/documents/{$document->id}/history?limit=100")->assertOk();
        $this->assertLessThanOrEqual(6, count(DB::getQueryLog()));
    }

    public function test_history_rejects_invalid_and_cross_document_cursors_and_validates_limits(): void
    {
        [$institution, $reader, , $document] = $this->context(RoleType::Reader, true);
        $other = $this->context(RoleType::Reader, true)[3];
        $sameInstitutionOther = Document::create([
            'name' => 'Other', 'status' => true, 'author_id' => $reader->id,
            'institution_id' => $institution->id, 'node_id' => $document->node_id,
        ]);
        Sanctum::actingAs($reader);

        foreach ([$document, $sameInstitutionOther, $other] as $index => $owner) {
            DocumentEvent::create([
                'document_id' => $owner->id, 'institution_id' => $owner->institution_id,
                'type' => 'document.created', 'detail' => [], 'origin' => 'recorded',
                'source_type' => 'cursor-test', 'source_id' => (string) $index, 'occurred_at' => now()->addSeconds($index),
            ]);
        }

        foreach (['not-base64', $this->cursor(['occurred_at' => now()->toJSON()]), $this->cursor(['occurred_at' => 12, 'id' => fake()->uuid()])] as $cursor) {
            $this->getJson("/api/v1/documents/{$document->id}/history?cursor=".urlencode($cursor))
                ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        foreach ([$sameInstitutionOther, $other] as $owner) {
            $event = DocumentEvent::where('document_id', $owner->id)->firstOrFail();
            $this->getJson("/api/v1/documents/{$document->id}/history?cursor=".urlencode($this->eventCursor($event)))
                ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }

        $valid = DocumentEvent::where('document_id', $document->id)->firstOrFail();
        $this->getJson("/api/v1/documents/{$document->id}/history?cursor=".urlencode($this->eventCursor($valid)))->assertOk();
        foreach ([1, 100] as $limit) {
            $this->getJson("/api/v1/documents/{$document->id}/history?limit={$limit}")->assertOk();
        }
        $this->getJson("/api/v1/documents/{$document->id}/history")->assertOk();
        foreach ([101, 0, -1, 'abc'] as $limit) {
            $this->getJson("/api/v1/documents/{$document->id}/history?limit={$limit}")
                ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
    }

    public function test_history_authentication_ability_and_node_access_fail_closed(): void
    {
        [$institution, $reader, $node, $document] = $this->context(RoleType::Reader, true);
        $uri = "/api/v1/documents/{$document->id}/history";
        $this->getJson($uri)->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');

        $withoutAbility = User::factory()->create(['institution_id' => null]);
        Sanctum::actingAs($withoutAbility);
        $this->getJson($uri)->assertForbidden();

        Sanctum::actingAs($reader);
        $document->update(['node_id' => null]);
        $this->getJson($uri)->assertNotFound();
        $document->update(['node_id' => $node->id]);
        $node->update(['active' => false]);
        $this->getJson($uri)->assertNotFound();

        $parent = Node::factory()->for($institution)->create(['active' => false]);
        $child = Node::factory()->for($institution)->create(['active' => true, 'parent_id' => $parent->id]);
        $document->update(['node_id' => $child->id]);
        $this->getJson($uri)->assertNotFound();
    }

    public function test_deactivation_and_promotion_events_are_atomic_and_capture_both_sides(): void
    {
        Storage::fake('local');
        [, $actor, $node] = $this->context(RoleType::Admin);
        Sanctum::actingAs($actor);
        $documentId = $this->postJson("/api/v1/institution/tree-directory/{$node->id}/documents", ['name' => 'Policy'])->assertCreated()->json('data.id');
        $first = $this->post("/api/v1/documents/{$documentId}/versions", ['file' => UploadedFile::fake()->createWithContent('one.pdf', "%PDF-1.4\n".str_repeat('0', 2048))], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $second = $this->post("/api/v1/documents/{$documentId}/versions", ['file' => UploadedFile::fake()->createWithContent('two.pdf', "%PDF-1.4\n".str_repeat('1', 2048))], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $before = DocumentEvent::where('document_id', $documentId)->count();
        $this->deleteJson("/api/v1/documents/{$documentId}/versions/{$first}")->assertOk();
        $this->assertSame($before, DocumentEvent::where('document_id', $documentId)->count());

        $this->deleteJson("/api/v1/documents/{$documentId}/versions/{$second}")->assertOk();
        $deactivation = DocumentEvent::where('document_id', $documentId)->where('type', 'document.current_version_changed')->latest('created_at')->firstOrFail();
        $this->assertSame($second, $deactivation->detail['previous_version']['id']);
        $this->assertNull($deactivation->detail['new_version']);

        $this->patchJson("/api/v1/documents/{$documentId}/versions/{$first}/activate")->assertOk();
        $this->patchJson("/api/v1/documents/{$documentId}/versions/{$first}/current")->assertOk();
        $promotion = DocumentEvent::where('document_id', $documentId)
            ->where('type', 'document.current_version_changed')->where('version_id', $first)->firstOrFail();
        $this->assertNull($promotion->detail['previous_version']);
        $this->assertSame($first, $promotion->detail['new_version']['id']);
        $count = DocumentEvent::where('document_id', $documentId)->count();
        $this->patchJson("/api/v1/documents/{$documentId}/versions/{$first}/current")->assertOk();
        $this->assertSame($count, DocumentEvent::where('document_id', $documentId)->count());
    }

    public function test_event_failure_rolls_back_deactivation_and_manual_promotion(): void
    {
        Storage::fake('local');
        [, $actor, $node] = $this->context(RoleType::Admin);
        Sanctum::actingAs($actor);
        $documentId = $this->postJson("/api/v1/institution/tree-directory/{$node->id}/documents", ['name' => 'Policy'])->assertCreated()->json('data.id');
        $first = $this->post("/api/v1/documents/{$documentId}/versions", ['file' => UploadedFile::fake()->createWithContent('one.pdf', "%PDF-1.4\n".str_repeat('0', 2048))], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $second = $this->post("/api/v1/documents/{$documentId}/versions", ['file' => UploadedFile::fake()->createWithContent('two.pdf', "%PDF-1.4\n".str_repeat('1', 2048))], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        DB::unprepared("CREATE TRIGGER fail_current_event BEFORE INSERT ON document_events WHEN NEW.type = 'document.current_version_changed' BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->deleteJson("/api/v1/documents/{$documentId}/versions/{$second}")->assertStatus(500);
        $this->assertTrue((bool) DocumentVersion::findOrFail($second)->active);
        $this->assertTrue((bool) DocumentVersion::findOrFail($second)->is_current);
        $this->patchJson("/api/v1/documents/{$documentId}/versions/{$first}/current")->assertStatus(500);
        $this->assertFalse((bool) DocumentVersion::findOrFail($first)->is_current);
        $this->assertTrue((bool) DocumentVersion::findOrFail($second)->is_current);
    }

    public function test_actor_projection_and_payload_privacy_cover_user_lifecycle_and_cross_tenant_data(): void
    {
        [$institution, $reader, , $document] = $this->context(RoleType::Reader, true);
        $active = User::factory()->for($institution)->create(['name' => 'Active', 'email' => 'active@example.test']);
        $inactive = User::factory()->for($institution)->inactive()->create(['name' => 'Inactive']);
        $deleted = User::factory()->for($institution)->create(['name' => 'Deleted']);
        $hardDeleted = User::factory()->for($institution)->create(['name' => 'Hard deleted']);
        $foreign = User::factory()->for(Institution::factory())->create(['name' => 'Foreign', 'email' => 'foreign@example.test']);
        foreach ([$active, $inactive, $deleted, $hardDeleted, $foreign] as $index => $user) {
            DocumentEvent::create([
                'document_id' => $document->id, 'institution_id' => $institution->id,
                'type' => 'document.created', 'actor_user_id' => $user->id,
                'actor_name' => $index === 0 ? 'Stale legacy name' : $user->name, 'detail' => ['secret' => $user->email],
                'origin' => 'legacy', 'source_type' => 'actor-test', 'source_id' => (string) $index,
                'occurred_at' => now()->addSeconds($index),
            ]);
        }
        $deleted->delete();
        $hardDeleted->forceDelete();
        Sanctum::actingAs($reader);

        $response = $this->getJson("/api/v1/documents/{$document->id}/history?limit=100")->assertOk();
        $byName = collect($response->json('data'))->keyBy('actor.name');
        $this->assertTrue($byName['Active']['actor']['active']);
        $this->assertFalse($byName['Inactive']['actor']['active']);
        $this->assertFalse($byName['Deleted']['actor']['active']);
        $this->assertCount(2, collect($response->json('data'))->whereNull('actor'));
        $this->assertStringNotContainsString('example.test', $response->getContent());
        $this->assertStringNotContainsString($foreign->id, $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertStringNotContainsString('legacy', $response->getContent());
    }

    public function test_document_access_is_private_and_empty_history_has_null_cursor(): void
    {
        [, $reader, , $document] = $this->context(RoleType::Reader, true);
        $foreign = $this->context(RoleType::Reader, true)[3];
        Sanctum::actingAs($reader);

        $this->getJson("/api/v1/documents/{$document->id}/history")
            ->assertOk()->assertExactJson([
                'success' => true, 'data' => [], 'meta' => ['next_cursor' => null],
                'message' => 'Document history retrieved successfully.',
            ]);
        foreach ([$foreign->id, fake()->uuid()] as $id) {
            $this->getJson("/api/v1/documents/{$id}/history")
                ->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_NOT_AVAILABLE');
        }
        $document->update(['status' => false]);
        $this->getJson("/api/v1/documents/{$document->id}/history")
            ->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_NOT_AVAILABLE');
    }

    public function test_event_failure_rolls_back_responsibility_state_and_specialized_history(): void
    {
        [$institution, $actor, , $document] = $this->context(RoleType::Admin, true);
        $responsible = User::factory()->for($institution)->create();
        Sanctum::actingAs($actor);
        DB::unprepared("CREATE TRIGGER fail_document_event BEFORE INSERT ON document_events BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->patchJson("/api/v1/documents/{$document->id}/responsible", [
            'responsible_user_id' => $responsible->id, 'expected_revision' => 0,
        ])->assertStatus(500);

        $document->refresh();
        $this->assertNull($document->responsible_user_id);
        $this->assertSame(0, $document->responsibility_revision);
        $this->assertDatabaseCount('document_responsible_histories', 0);
        $this->assertDatabaseCount('document_events', 0);
    }

    public function test_event_failures_roll_back_document_creation_and_version_upload(): void
    {
        Storage::fake('local');
        [, $actor, $node] = $this->context(RoleType::Admin);
        Sanctum::actingAs($actor);
        DB::unprepared("CREATE TRIGGER fail_created_event BEFORE INSERT ON document_events WHEN NEW.type = 'document.created' BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->postJson("/api/v1/institution/tree-directory/{$node->id}/documents", ['name' => 'Rejected'])
            ->assertStatus(500);
        $this->assertDatabaseMissing('documents', ['name' => 'Rejected']);
        DB::unprepared('DROP TRIGGER fail_created_event');

        $documentId = $this->postJson("/api/v1/institution/tree-directory/{$node->id}/documents", ['name' => 'Policy'])
            ->assertCreated()->json('data.id');
        $firstVersionId = $this->post("/api/v1/documents/{$documentId}/versions", [
            'file' => UploadedFile::fake()->createWithContent('one.pdf', "%PDF-1.4\n".str_repeat('0', 2048)),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        DB::unprepared("CREATE TRIGGER fail_upload_event BEFORE INSERT ON document_events WHEN NEW.type = 'document.version_uploaded' BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

        $this->post("/api/v1/documents/{$documentId}/versions", [
            'file' => UploadedFile::fake()->createWithContent('two.pdf', "%PDF-1.4\n".str_repeat('1', 2048)),
        ], ['Accept' => 'application/json'])->assertStatus(500)->assertJsonPath('error.code', 'DOCUMENT_VERSION_CREATION_FAILED');

        $this->assertSame(1, DocumentVersion::where('document_id', $documentId)->count());
        $this->assertTrue((bool) DocumentVersion::findOrFail($firstVersionId)->is_current);
        $this->assertSame(1, DocumentEvent::where('document_id', $documentId)->where('type', 'document.version_uploaded')->count());
    }

    private function context(RoleType $role, bool $document = false): array
    {
        $institution = Institution::factory()->create();
        $actor = User::factory()->for($institution)->create();
        $actor->roles()->attach(Rol::firstOrCreate(['type' => $role]));
        $node = Node::factory()->for($institution)->create(['active' => true]);
        $model = $document ? Document::create([
            'name' => 'Policy', 'status' => true, 'author_id' => $actor->id,
            'institution_id' => $institution->id, 'node_id' => $node->id,
        ]) : null;

        return [$institution, $actor, $node, $model];
    }

    /** @param array<string, mixed> $parameters */
    private function cursor(array $parameters): string
    {
        return (new Cursor($parameters, true))->encode();
    }

    private function eventCursor(DocumentEvent $event): string
    {
        return $this->cursor(['occurred_at' => $event->getRawOriginal('occurred_at'), 'id' => $event->id]);
    }
}
