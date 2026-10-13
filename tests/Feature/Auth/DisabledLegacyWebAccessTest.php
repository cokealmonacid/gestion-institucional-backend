<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisabledLegacyWebAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_and_alternative_access_lifecycle_routes_are_disabled(): void
    {
        foreach ([
            ['GET', '/register'],
            ['POST', '/register'],
            ['GET', '/forgot-password'],
            ['POST', '/forgot-password'],
            ['GET', '/reset-password/token'],
            ['POST', '/reset-password'],
        ] as [$method, $path]) {
            $this->call($method, $path)->assertNotFound();
        }
    }

    public function test_authenticated_web_profile_verification_and_password_routes_are_disabled(): void
    {
        $user = User::factory()->create();
        $original = $user->only(['name', 'email', 'password', 'email_verified_at', 'deleted_at']);

        foreach ([
            ['GET', '/profile'],
            ['PATCH', '/profile'],
            ['DELETE', '/profile'],
            ['GET', '/verify-email'],
            ['POST', '/email/verification-notification'],
            ['GET', '/confirm-password'],
            ['POST', '/confirm-password'],
            ['PUT', '/password'],
        ] as [$method, $path]) {
            $this->actingAs($user)->call($method, $path)->assertNotFound();
        }

        $fresh = $user->fresh();
        $this->assertSame($original['name'], $fresh->name);
        $this->assertSame($original['email'], $fresh->email);
        $this->assertSame($original['password'], $fresh->password);
        $this->assertEquals($original['email_verified_at'], $fresh->email_verified_at);
        $this->assertNull($fresh->deleted_at);
    }
}
