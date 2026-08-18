<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Institution\Models\Institution;
use Modules\Institution\Models\Tag;
use Tests\TestCase;

class InstitutionTagsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_three_tag_operations_require_authentication(): void
    {
        $this->getJson('/api/v1/institution/tag')->assertUnauthorized();
        $this->postJson('/api/v1/institution/tag', [])->assertUnauthorized();
        $this->deleteJson('/api/v1/institution/tag/tag')->assertUnauthorized();
    }

    public function test_index_lists_only_tags_from_the_authenticated_institution(): void
    {
        [$institution, $user] = $this->institutionUser();
        $own = Tag::factory()->create(['institution_id' => $institution->id, 'name' => 'Own']);
        Tag::factory()->create(['institution_id' => Institution::factory(), 'name' => 'Foreign']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/institution/tag')->assertOk()
            ->assertJsonPath('message', 'Tags retrieved successfully.')
            ->assertJsonPath('data.total', 1);

        $this->assertSame($own->id, $response->json('data.data.0.id'));
        $this->assertSame(['id', 'name', 'description', 'created_at', 'status'], array_keys($response->json('data.data.0')));
    }

    public function test_matching_institution_can_create_and_delete_a_tag(): void
    {
        [$institution, $user] = $this->institutionUser();
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/v1/institution/tag', [
            'institution_id' => $institution->id,
            'name' => 'Contracts',
            'description' => 'Legal documents',
        ])->assertOk()->assertJsonPath('message', 'Tag created successfully.');

        $tagId = $created->json('data.id');
        $this->assertDatabaseHas('tags', ['id' => $tagId, 'institution_id' => $institution->id]);

        $this->deleteJson("/api/v1/institution/tag/{$tagId}?institution_id={$institution->id}")
            ->assertOk()->assertJsonPath('message', 'Tag removed successfully.');
        $this->assertDatabaseMissing('tags', ['id' => $tagId]);
    }

    public function test_creation_rejects_a_different_institution_and_invalid_input(): void
    {
        [, $user] = $this->institutionUser();
        $other = Institution::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/institution/tag', [
            'institution_id' => $other->id,
            'name' => 'Foreign',
        ])->assertForbidden()->assertExactJson([
            'message' => 'Forbidden.',
        ]);

        $this->postJson('/api/v1/institution/tag', [
            'institution_id' => $user->institution_id,
        ])->assertUnprocessable()->assertJsonPath('message', 'Validation failed.');
    }

    /** @return array{Institution, User} */
    private function institutionUser(): array
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create();

        return [$institution, $user];
    }
}
