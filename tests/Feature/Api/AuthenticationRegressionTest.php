<?php

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationRegressionTest extends TestCase
{
    #[DataProvider('protectedApiEndpointsOutsideAuthenticationContract')]
    public function test_protected_api_endpoints_outside_the_contract_keep_the_historical_401(
        string $method,
        string $uri,
    ): void {
        $this->json($method, $uri)
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function protectedApiEndpointsOutsideAuthenticationContract(): array
    {
        return [
            'user profile update' => ['PUT', '/api/v1/user/profile'],
            'user password update' => ['PATCH', '/api/v1/user/update-password'],
            'nodes module' => ['GET', '/api/v1/nodes'],
            'institution module' => ['GET', '/api/v1/institution/tree-directory'],
            'documents module' => ['GET', '/api/v1/documents/example-document'],
        ];
    }

    public function test_protected_web_route_keeps_redirecting_guests(): void
    {
        $this->get('/dashboard')->assertRedirect('/');
    }
}
