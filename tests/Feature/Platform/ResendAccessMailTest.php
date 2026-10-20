<?php

namespace Tests\Feature\Platform;

use App\Jobs\SendPasswordRecovery;
use App\Jobs\SendUserInvitation;
use App\Models\User;
use App\Platform\Http\Controllers\OperationalController;
use App\Platform\Jobs\SendInstitutionRecoveryProof;
use App\Platform\Jobs\SendPlatformAccessLink;
use App\Platform\Models\PlatformAccessRecovery;
use App\Platform\Services\PlatformAccessLinks;
use App\Platform\Services\PlatformReadiness;
use App\Services\UserAccess\InvitationService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Modules\Institution\Models\Institution;
use Resend\Client;
use Resend\Transporters\HttpTransporter;
use Resend\ValueObjects\ApiKey;
use Resend\ValueObjects\Transporter\BaseUri;
use Resend\ValueObjects\Transporter\Headers;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class ResendAccessMailTest extends PlatformTestCase
{
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        config(['mail.default' => 'resend', 'services.resend.key' => 'synthetic-test-key',
            'mail.from.address' => 'sender@example.test', 'mail.from.name' => 'Acervo test',
            'platform.frontend_url' => 'https://platform.example.test', 'user-access.frontend_url' => 'https://institution.example.test']);
    }

    private function transport(array $responses): void
    {
        $mailer = app(MailManager::class)->mailer('resend');
        // Resolve the unmodified native Laravel factory, then substitute only the
        // SDK's network boundary. MockHandler never falls through to a real socket.
        $this->assertInstanceOf(ResendTransport::class, $mailer->getSymfonyTransport());
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));
        $client = new Client(new HttpTransporter(new HttpClient(['handler' => $stack]),
            BaseUri::from('api.resend.com'), Headers::withAuthorization(ApiKey::from('synthetic-test-key'))));
        $mailer->setSymfonyTransport(new ResendTransport($client));
    }

    public function test_administrator_invitation_route_resolves_its_controller_with_the_resend_sdk_loaded(): void
    {
        $this->assertTrue(class_exists(\Resend::class));
        $route = Route::getRoutes()->match(\Illuminate\Http\Request::create(
            '/api/v1/platform/institutions/synthetic-institution/administrators/synthetic-user/invitation', 'POST'));
        $this->assertSame(OperationalController::class.'@resend', $route->getActionName());
        $this->assertContains('platform.auth', $route->gatherMiddleware());
        $this->assertContains('platform.can:platform.administrator_access', $route->gatherMiddleware());
    }

    private function fixture(string $flow): array
    {
        if (str_starts_with($flow, 'operator_')) {
            $operator = $this->operator();
            $purpose = $flow === 'operator_activation' ? 'activation' : 'password_reset';
            if ($purpose === 'activation') {
                $operator->update(['verified_at' => null, 'password' => null]);
            }
            [$record, $proof] = DB::transaction(fn () => app(PlatformAccessLinks::class)->issueLocked($operator, $purpose));

            return [new SendPlatformAccessLink($record->id, $record->generation, $proof), $record,
                'https://platform.example.test/platform/'.($purpose === 'activation' ? 'activate' : 'password-reset').'#email=', $operator];
        }
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create();
        if ($flow === 'invitation') {
            $user->forceFill(['email_verified_at' => null, 'password' => null])->save();
            [$record, $proof] = DB::transaction(fn () => app(InvitationService::class)->issueLocked($user));

            return [new SendUserInvitation($record->id, $record->generation, $proof), $record,
                'https://institution.example.test/activar-cuenta?email=', $user];
        }
        if ($flow === 'password_recovery') {
            $proof = Password::broker()->createToken($user);

            return [new SendPasswordRecovery($user->id, $proof), null,
                'https://institution.example.test/recuperar-contrasena?email=', $user];
        }
        $operator = $this->operator();
        $proof = 'synthetic-purpose-bound-proof';
        $record = PlatformAccessRecovery::create(['operator_id' => $operator->id, 'institution_id' => $institution->id, 'target_id' => $user->id,
            'kind' => 'email_correction', 'state' => 'awaiting_verification', 'fingerprint' => str_repeat('a', 64),
            'request' => ['new_email' => 'replacement@example.test'], 'snapshot' => ['successor_email' => 'replacement@example.test'],
            'verification_hash' => Hash::make($proof), 'expires_at' => now()->addHour()]);

        return [new SendInstitutionRecoveryProof($record->id, $record->generation, $proof), $record,
            'https://platform.example.test/platform/access-verification#operation=', $user];
    }

    public function test_every_access_flow_uses_native_resend_https_and_preserves_its_link_destination_and_state(): void
    {
        $flows = ['invitation', 'password_recovery', 'operator_activation', 'operator_reset', 'institution_proof'];
        $this->transport(array_map(fn () => new Response(200, ['Content-Type' => 'application/json'], '{"id":"synthetic-message-id"}'), $flows));
        foreach ($flows as $index => $flow) {
            [$job, $record, $url, $identity] = $this->fixture($flow);
            $before = $identity->fresh()->getAttributes();
            $job->handle();
            $this->assertCount($index + 1, $this->requests);
            $request = $this->requests[$index]['request'];
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('https', $request->getUri()->getScheme());
            $this->assertSame('api.resend.com', $request->getUri()->getHost());
            $this->assertSame('/emails', $request->getUri()->getPath());
            $this->assertSame('Bearer synthetic-test-key', $request->getHeaderLine('Authorization'));
            $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('sender@example.test', $payload['from']);
            $this->assertStringContainsString($url, $payload['text'] ?? $payload['html']);
            if ($record) {
                $this->assertSame('accepted', $record->fresh()->{$flow === 'invitation' ? 'delivery_status' : 'publication_status'});
            }
            $this->assertSame($before, $identity->fresh()->getAttributes());
        }
    }

    public function test_api_rejections_are_sanitized_for_every_flow_and_publication_is_marked_failed(): void
    {
        Log::spy();
        $flows = ['invitation', 'password_recovery', 'operator_activation', 'operator_reset', 'institution_proof'];
        $this->transport(array_map(fn () => new Response(403, ['Content-Type' => 'application/json'],
            '{"name":"validation_error","message":"synthetic-sensitive-provider-context"}'), $flows));
        foreach ($flows as $flow) {
            [$job, $record] = $this->fixture($flow);
            try {
                $job->handle();
                $this->fail('Provider rejection was treated as success.');
            } catch (RuntimeException $error) {
                $this->assertStringNotContainsString('synthetic-sensitive-provider-context', $error->getMessage());
                $this->assertNull($error->getPrevious());
            }
            if ($record) {
                $this->assertSame('failed', $record->fresh()->{$flow === 'invitation' ? 'delivery_status' : 'publication_status'});
            }
        }
        $this->assertCount(5, $this->requests);
        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) {
            return ! str_contains(json_encode([$message, $context]), 'synthetic-sensitive-provider-context');
        });
    }

    public function test_ineligible_or_obsolete_jobs_do_not_reach_resend(): void
    {
        $this->transport([]);
        foreach (['invitation', 'password_recovery', 'operator_activation', 'institution_proof'] as $flow) {
            [$job, $record, , $identity] = $this->fixture($flow);
            if ($flow === 'institution_proof') {
                $record->update(['state' => 'cancelled']);
            } else {
                $identity->update(['active' => false]);
            }
            $job->handle();
        }
        $this->assertSame([], $this->requests);
    }

    public function test_stale_generations_and_revoked_recovery_tokens_do_not_reach_resend(): void
    {
        $this->transport([]);
        foreach (['invitation', 'password_recovery', 'operator_activation', 'operator_reset', 'institution_proof'] as $flow) {
            [$job, $record, , $identity] = $this->fixture($flow);
            if ($record) {
                $record->increment('generation');
            } else {
                Password::broker()->deleteToken($identity);
            }
            $job->handle();
        }
        $this->assertSame([], $this->requests);
    }

    public function test_connection_failures_are_sanitized_for_every_access_flow(): void
    {
        Log::spy();
        $flows = ['invitation', 'password_recovery', 'operator_activation', 'operator_reset', 'institution_proof'];
        $this->transport(array_map(fn () => new ConnectException('synthetic-sensitive-network-context',
            new Request('POST', 'https://api.resend.com/emails')), $flows));
        foreach ($flows as $flow) {
            [$job, $record] = $this->fixture($flow);
            try {
                $job->handle();
                $this->fail('Connection failure was treated as success.');
            } catch (RuntimeException $error) {
                $this->assertStringNotContainsString('synthetic-sensitive-network-context', $error->getMessage());
                $this->assertNull($error->getPrevious());
            }
            if ($record) {
                $this->assertSame('failed', $record->fresh()->{$flow === 'invitation' ? 'delivery_status' : 'publication_status'});
            }
        }
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => ! str_contains(json_encode([$message, $context]), 'synthetic-sensitive-network-context'));
    }

    public function test_readiness_reports_only_resend_dependency_and_key_presence_without_disclosing_key(): void
    {
        $readiness = app(PlatformReadiness::class);
        config(['services.resend.key' => null]);
        $this->assertFalse($readiness->inspect()['local_configuration_checks']['resend_sdk_and_key_configured']);
        config(['services.resend.key' => '  ']);
        $this->assertFalse($readiness->inspect()['local_configuration_checks']['resend_sdk_and_key_configured']);
        config(['services.resend.key' => 'synthetic-private-key']);
        $report = $readiness->inspect();
        $this->assertTrue($report['local_configuration_checks']['resend_sdk_and_key_configured']);
        $this->assertStringNotContainsString('synthetic-private-key', json_encode($report));
        config(['mail.default' => 'smtp', 'services.resend.key' => null]);
        $this->assertTrue($readiness->inspect()['local_configuration_checks']['resend_sdk_and_key_configured']);
        $this->assertInstanceOf(EsmtpTransport::class, app(MailManager::class)->mailer('smtp')->getSymfonyTransport());
    }
}
