<?php

namespace Tests\Feature\Platform;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use App\Platform\Services\PlatformReadiness;
use App\Services\UserAccess\InvitationService;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Institution\Models\Institution;

class PlatformReadinessTest extends PlatformTestCase
{
    public function test_readiness_reports_only_local_evidence_and_counts_without_writes_or_personal_data(): void
    {
        foreach (RoleType::cases() as $role) {
            Rol::firstOrCreate(['type' => $role]);
        }
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create(['email' => 'synthetic-private@example.test']);
        $user->roles()->attach(Rol::where('type', 'admin')->first()->id);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $report = app(PlatformReadiness::class)->inspect();
        $this->assertTrue($report['data_preflight_passed']);
        $this->assertFalse($report['ready_for_production_activation']);
        $this->assertFalse($report['local_configuration_checks']['supported_mysql_driver']);
        $this->assertNotEmpty($report['deployment_verification_required']);
        $this->assertStringNotContainsString($user->email, json_encode($report));
        $this->assertStringNotContainsString($user->name, json_encode($report));
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|alter|create|drop|replace)\b/i', $sql);
        }
        Artisan::call('platform:preflight');
        $this->assertStringNotContainsString($user->email, Artisan::output());
        $this->assertStringNotContainsString('password', Artisan::output());
    }

    public function test_missing_schema_and_legacy_data_are_diagnosed_without_repairs(): void
    {
        $institution = Institution::factory()->create();
        User::factory()->for($institution)->create(['email' => 'CASE@example.test']);
        User::factory()->for($institution)->create(['email' => 'case@example.test']);
        $report = app(PlatformReadiness::class)->inspect();
        $this->assertFalse($report['data_preflight_passed']);
        $this->assertSame(1, $report['data_findings']['normalized_email_collision_groups']);
        $this->assertSame(1, $report['data_findings']['institutions_without_usable_admin']);
        $this->assertSame(2, User::count());
        $this->assertSame('CASE@example.test', User::where('email', 'CASE@example.test')->first()->email);
        Schema::drop('platform_operations');
        $report = app(PlatformReadiness::class)->inspect();
        $this->assertContains('platform_operations', $report['missing_schema']);
        $this->assertNull($report['data_findings']);
    }

    public function test_reconciliation_is_read_only_paginated_stale_detection_and_never_reconstructs_tokens(): void
    {
        Bus::fake();
        $institution = Institution::factory()->create();
        $role = Rol::firstOrCreate(['type' => RoleType::Admin]);
        $user = User::factory()->for($institution)->create(['email' => 'reconciliation-private@example.test', 'password' => null, 'email_verified_at' => null]);
        $user->roles()->attach($role->id);
        [$invitation, $plain] = DB::transaction(fn () => app(InvitationService::class)->issueLocked($user));
        $this->assertSame(0, Artisan::call('platform:invitations:reconcile'));
        $this->assertSame([], json_decode(Artisan::output(), true)['items']);
        $this->travel(5)->minutes();
        $this->assertSame(0, Artisan::call('platform:invitations:reconcile', ['--limit' => '1']));
        $output = Artisan::output();
        $item = json_decode($output, true)['items'][0];
        $this->assertSame($invitation->id, $item['invitation_id']);
        $this->assertSame('unknown', $item['publication_status']);
        $this->assertTrue($item['administrator']);
        $this->assertStringNotContainsString($plain, $output);
        $this->assertStringNotContainsString($user->email, $output);
        $this->assertSame(1, $invitation->fresh()->generation);
        Bus::assertNothingDispatched();
        $this->assertSame(1, Artisan::call('platform:invitations:reconcile', ['--limit' => '1001']));
        $this->assertSame(1, Artisan::call('platform:invitations:reconcile', ['--after' => 'invalid']));
    }

    public function test_dashboard_renders_without_reenabling_the_disabled_profile_routes(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $this->get('/profile')->assertNotFound();
        $this->patch('/profile', ['name' => 'Must not change'])->assertNotFound();
    }

    public function test_role_only_bootstrap_is_repeatable_and_creates_no_customer_or_user(): void
    {
        $admin = Rol::firstOrCreate(['type' => RoleType::Admin]);
        $this->seed(RolesSeeder::class);
        $this->seed(RolesSeeder::class);
        $this->assertSame(3, Rol::count());
        $this->assertSame($admin->id, Rol::where('type', 'admin')->first()->id);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('institutions', 0);
    }
}
