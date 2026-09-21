<?php

namespace Tests\Feature\Api;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\Document;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use Tests\TestCase;

class DocumentSearchContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_operation_requires_authentication(): void
    {
        $this->getJson('/api/v1/institution/documents/search?q=policy')
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    public function test_an_institution_reader_finds_active_local_documents_by_partial_case_insensitive_name(): void
    {
        [$institution, $user] = $this->institutionUser(RoleType::Reader);
        $node = $this->node($institution);
        $match = $this->document($institution, $node, 'Institutional Policy');
        $this->document($institution, $node, 'Annual report');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/institution/documents/search?q=POLIcy')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Documents retrieved successfully.')
            ->assertJsonCount(1, 'data.documents');

        $entry = $response->json('data.documents.0');
        $this->assertSame($match->id, $entry['id']);
        $this->assertSame('Institutional Policy', $entry['name']);
        $this->assertSame([
            'author', 'category', 'created_at', 'description', 'id', 'lifecycle', 'name',
            'node_id', 'responsible_unit', 'status', 'updated_at',
        ], $this->sortedKeys($entry));
    }

    public function test_search_excludes_inactive_and_foreign_documents(): void
    {
        [$institution, $user] = $this->institutionUser(RoleType::Reader);
        $localNode = $this->node($institution);
        $active = $this->document($institution, $localNode, 'Shared policy');
        $inactive = $this->document($institution, $localNode, 'Shared archived policy', false);
        $otherInstitution = Institution::factory()->create();
        $foreign = $this->document($otherInstitution, $this->node($otherInstitution), 'Shared confidential policy');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/institution/documents/search?q=shared')
            ->assertOk()
            ->assertJsonCount(1, 'data.documents')
            ->assertJsonPath('data.documents.0.id', $active->id);

        foreach ([$inactive, $foreign] as $excluded) {
            $this->assertStringNotContainsString($excluded->id, $response->getContent());
            $this->assertStringNotContainsString($excluded->name, $response->getContent());
        }
    }

    public function test_results_are_limited_to_ten_and_ordered_by_name(): void
    {
        [$institution, $user] = $this->institutionUser(RoleType::Reader);
        $node = $this->node($institution);
        $first = $this->document($institution, $node, 'Match 00');
        foreach (range(1, 11) as $number) {
            $this->document($institution, $node, sprintf('Match %02d', $number));
        }

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/institution/documents/search?q=match')
            ->assertOk()
            ->assertJsonCount(10, 'data.documents');

        $this->assertSame($first->id, $response->json('data.documents.0.id'));
    }

    public function test_query_validation_is_preserved(): void
    {
        [, $user] = $this->institutionUser(RoleType::Reader);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/institution/documents/search?q=x')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonPath('data.error.q.0', 'The q field must be at least 2 characters.');
        $this->getJson('/api/v1/institution/documents/search')
            ->assertUnprocessable()
            ->assertJsonPath('data.error.q.0', 'The q field is required.');
    }

    /** @return array{Institution, User} */
    private function institutionUser(RoleType $role): array
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create();
        $user->roles()->attach(Rol::firstOrCreate(['type' => $role]));

        return [$institution, $user];
    }

    private function node(Institution $institution): Node
    {
        $node = new Node;
        $node->id = $node->newUniqueId();
        $node->fill([
            'name' => 'Documents',
            'path' => $node->id,
            'depth' => 0,
            'order' => '1',
            'active' => true,
            'institution_id' => $institution->id,
            'parent_id' => null,
        ]);
        $node->save();

        return $node;
    }

    private function document(Institution $institution, Node $node, string $name, bool $status = true): Document
    {
        return Document::create([
            'name' => $name,
            'status' => $status,
            'institution_id' => $institution->id,
            'node_id' => $node->id,
        ]);
    }

    /** @return list<string> */
    private function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);

        return $keys;
    }
}
