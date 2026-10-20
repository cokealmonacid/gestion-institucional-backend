<?php

namespace Tests\Feature\Platform;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use App\Platform\Jobs\SendPlatformAccessLink;
use App\Platform\Services\PlatformAccessLinks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Modules\Institution\Models\Institution;

class PlatformPanelTest extends PlatformTestCase
{
    public function test_public_shell_is_stateless_contains_no_administrative_data_and_has_link_privacy_headers(): void
    {
        $operator = $this->operator();
        $institution = Institution::factory()->create(['name' => 'Private synthetic customer']);
        foreach (['/platform', '/platform/login', '/platform/activate?email=private@example.test&token=private-proof', '/platform/password-reset?token=private-proof'] as $path) {
            $response = $this->get($path)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee('Administración de plataforma')->assertDontSee($operator->email)
                ->assertDontSee($institution->name)->assertDontSee('private-proof')->assertDontSee('private@example.test');
            $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
            $this->assertSame([], $response->headers->getCookies());
        }
        $this->get('/platform/unexpected')->assertNotFound();
        $this->actingAs($operator)->getJson('/api/v1/platform/institutions')->assertUnauthorized();
    }

    public function test_mail_link_builder_points_to_the_implemented_activation_and_reset_shells(): void
    {
        Mail::spy();
        config(['mail.default' => 'array', 'platform.frontend_url' => 'https://panel.example.test']);
        $operator = $this->operator(false);
        $operator->update(['verified_at' => null, 'password' => null]);
        foreach (['activation' => 'activate', 'password_reset' => 'password-reset'] as $purpose => $page) {
            if ($purpose === 'password_reset') {
                $operator->update(['verified_at' => now(), 'password' => 'Synthetic-password-123']);
            }
            [$link, $plain] = DB::transaction(fn () => app(PlatformAccessLinks::class)->issueLocked($operator, $purpose));
            (new SendPlatformAccessLink($link->id, $link->generation, $plain))->handle();
            Mail::shouldHaveReceived('raw')->withArgs(fn ($text, $callback) => str_contains($text, '/platform/'.$page.'#email=') && str_contains($text, 'token='.rawurlencode($plain)) && ! str_contains($text, '?email='))->once();
            $this->get('/platform/'.$page)->assertOk();
        }
    }

    public function test_administrator_directory_is_paginated_scoped_metadata_only_and_explicitly_authorized(): void
    {
        $role = Rol::firstOrCreate(['type' => RoleType::Admin]);
        $readerRole = Rol::firstOrCreate(['type' => RoleType::Reader]);
        $institution = Institution::factory()->create(['status' => false]);
        $admin = User::factory()->create(['institution_id' => $institution->id, 'name' => 'A synthetic admin']);
        $admin->roles()->sync([$role->id]);
        $pending = User::factory()->create(['institution_id' => $institution->id, 'name' => 'B synthetic pending', 'email_verified_at' => null, 'password' => null, 'active' => false]);
        $pending->roles()->sync([$role->id]);
        $foreign = User::factory()->create(['institution_id' => Institution::factory()->create()->id]);
        $foreign->roles()->sync([$role->id]);
        $reader = User::factory()->create(['institution_id' => $institution->id]);
        $reader->roles()->sync([$readerRole->id]);
        $deleted = User::factory()->create(['institution_id' => $institution->id]);
        $deleted->roles()->sync([$role->id]);
        $deleted->delete();
        $path = '/api/v1/platform/institutions/'.$institution->id.'/administrators';
        $this->getJson($path)->assertUnauthorized();
        $operator = $this->operator();
        $this->withToken($this->token($operator));
        $response = $this->getJson($path.'?per_page=1')->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.last_page', 2)->assertJsonPath('data.items.0.user_id', $admin->id);
        $this->assertSame(['user_id', 'name', 'email', 'active', 'activation_status', 'access_revision'], array_keys($response->json('data.items.0')));
        $this->getJson($path.'?per_page=1&page=2')->assertOk()->assertJsonPath('data.items.0.user_id', $pending->id)->assertJsonPath('data.items.0.activation_status', 'pending_activation')->assertJsonPath('data.items.0.active', false);
        $this->getJson($path.'?q='.urlencode($pending->email))->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson($path.'?password=forbidden')->assertUnprocessable();
        $this->getJson($path.'?per_page=101')->assertUnprocessable();
        Gate::define('platform.administrator_access', fn () => false);
        $this->getJson($path)->assertForbidden();
    }

    public function test_new_directory_never_accepts_institutional_tokens_or_pre_mfa_challenges(): void
    {
        $institution = Institution::factory()->create();
        $path = '/api/v1/platform/institutions/'.$institution->id.'/administrators';
        $user = User::factory()->create(['institution_id' => $institution->id]);
        $this->withToken($user->createToken('institution', ['*'])->plainTextToken)->getJson($path)->assertUnauthorized();
        $operator = $this->operator(false);
        $challenge = $this->postJson('/api/v1/platform/auth/login', ['email' => $operator->email, 'password' => 'Valid-test-password-123'])->assertStatus(202)->json('data');
        $this->withToken($challenge['challenge_token'])->getJson($path)->assertUnauthorized();
        $token = $this->token($operator);
        $this->withToken($token)->getJson($path)->assertForbidden();
    }
}
