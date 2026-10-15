<?php

namespace Tests\Feature\Platform;

use App\Platform\Jobs\SendPlatformAccessLink;
use App\Platform\Models\AdministrativeEvent;
use App\Platform\Models\PlatformOperator;
use App\Platform\Services\AdministrativeEventRecorder;
use App\Platform\Services\PlatformAccessLinks;
use App\Platform\Services\PlatformOperatorProvisioning;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlatformProvisioningTest extends PlatformTestCase
{
    private function input(): array
    {
        return ['operation_id' => (string) Str::uuid(), 'name' => 'Provisioned fixture', 'email' => 'provisioned@example.test', 'reason' => 'Initial setup', 'accountability' => 'test-operations'];
    }

    public function test_bootstrap_is_idempotent_and_activation_never_issues_admin_credentials(): void
    {
        Bus::fake();
        $d = $this->input();
        $service = app(PlatformOperatorProvisioning::class);
        $result = $service->execute('bootstrap', $d);
        $again = $service->execute('bootstrap', $d);
        $this->assertTrue($again['replayed']);
        $this->assertSame($result['operator_id'], $again['operator_id']);
        $this->assertDatabaseCount('platform_operators', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Bus::assertDispatchedTimes(SendPlatformAccessLink::class, 1);
        $operator = PlatformOperator::first();
        [$link,$token] = DB::transaction(fn () => app(PlatformAccessLinks::class)->issueLocked($operator, 'activation'));
        $this->postJson('/api/v1/platform/auth/activation', ['email' => $operator->email, 'token' => $token, 'password' => 'New-operator-password-123', 'password_confirmation' => 'New-operator-password-123'])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull($operator->fresh()->mfa_confirmed_at);
        $this->assertNotNull($operator->fresh()->verified_at);
        $this->assertStringNotContainsString($token, AdministrativeEvent::all()->toJson());
    }

    public function test_cli_recovery_requires_accountability_and_external_identity_verification(): void
    {
        Bus::fake();
        $operator = $this->operator();
        $old = $this->token($operator);
        $args = ['--operator-id' => $operator->id, '--operation-id' => (string) Str::uuid(), '--reason' => 'Incident recovery', '--accountability' => 'test-operations'];
        $this->artisan('platform:operator:recover-mfa', $args)->assertFailed();
        $this->assertNotNull($operator->fresh()->mfa_confirmed_at);
        $this->artisan('platform:operator:recover-mfa', $args + ['--identity-verified' => true])->assertSuccessful();
        $this->assertNull($operator->fresh()->mfa_confirmed_at);
        $this->withToken($old)->getJson('/api/v1/platform/plans')->assertUnauthorized();
    }

    public function test_audit_failure_rolls_back_operator_link_and_operation(): void
    {
        Bus::fake();
        $this->mock(AdministrativeEventRecorder::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(PlatformOperatorProvisioning::class)->execute('bootstrap', $this->input());
            $this->fail();
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('platform_operators', 0);
        $this->assertDatabaseCount('platform_access_links', 0);
        $this->assertDatabaseCount('platform_cli_operations', 0);
        Bus::assertNothingDispatched();
    }

    public function test_cli_output_and_publication_failure_preserve_discoverable_operator_without_secrets(): void
    {
        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('Sensitive provider content'));
        $result = app(PlatformOperatorProvisioning::class)->execute('bootstrap', $this->input());
        $this->assertSame('failed', $result['publication_status']);
        $this->assertSame(['operator_id', 'replayed', 'publication_status'], array_keys($result));
        $this->assertDatabaseCount('platform_operators', 1);
        $this->assertDatabaseCount('platform_access_links', 1);
        $this->assertStringNotContainsString('Sensitive', AdministrativeEvent::all()->toJson());
    }
}
