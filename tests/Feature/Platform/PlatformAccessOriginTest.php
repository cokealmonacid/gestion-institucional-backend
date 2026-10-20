<?php

namespace Tests\Feature\Platform;

use App\Platform\Jobs\SendPlatformAccessLink;
use App\Platform\Services\PlatformAccessLinks;
use App\Platform\Services\PlatformAccessOrigin;
use App\Platform\Services\PlatformReadiness;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class PlatformAccessOriginTest extends PlatformTestCase
{
    public function test_origin_policy_is_shared_by_readiness_and_publication(): void
    {
        $operator = $this->operator(false);
        $operator->update(['verified_at' => null, 'password' => null]);
        $cases = [
            ['local', true, 'http://127.0.0.1:8000', true],
            ['local', true, 'http://localhost:8000/', true],
            ['local', false, 'http://127.0.0.1:8000', false],
            ['production', true, 'http://127.0.0.1:8000', false],
            ['staging', true, 'http://localhost:8000', false],
            ['testing', true, 'http://127.0.0.1:8000', false],
            ['LOCAL', true, 'http://localhost:8000', false],
            ['production', false, 'https://panel.example.test', true],
        ];
        foreach (['http://example.test', 'http://localhost.example.test', 'http://127.0.0.1.example.test',
            'http://localhost.', 'http://127.1', 'http://[::1]', 'http://localhost@evil.test',
            'http://user:pass@localhost', 'https://user@panel.example.test', 'http://localhost/platform',
            'https://panel.example.test/path', 'http://localhost?x=1', 'http://localhost#fragment',
            'http://localhost?', 'http://localhost#', 'http://localhost:99999', 'http:///localhost',
            'http://localhost\\@evil.test', "http://localhost\n", 'not a URL'] as $url) {
            $cases[] = ['local', true, $url, false];
        }
        foreach ($cases as [$environment, $flag, $url, $allowed]) {
            config(['app.env' => $environment, 'platform.allow_local_http' => $flag,
                'platform.frontend_url' => $url, 'user-access.frontend_url' => 'https://institution.example.test',
                'mail.default' => 'array']);
            Mail::swap(\Mockery::spy(MailManager::class));
            $this->assertSame($allowed, PlatformAccessOrigin::allowed($url), $url);
            $this->assertSame($allowed, app(PlatformReadiness::class)->inspect()['local_configuration_checks']['trusted_https_origins'], $url);
            [$link, $plain] = DB::transaction(fn () => app(PlatformAccessLinks::class)->issueLocked($operator, 'activation'));
            try {
                (new SendPlatformAccessLink($link->id, $link->generation, $plain))->handle();
                $this->assertTrue($allowed, $url);
            } catch (RuntimeException $exception) {
                $this->assertFalse($allowed, $url);
                $this->assertSame('Platform access publication failed.', $exception->getMessage());
            }
            $this->assertSame($allowed ? 'accepted' : 'failed', $link->fresh()->publication_status, $url);
            if ($allowed) {
                Mail::shouldHaveReceived('raw')->withArgs(fn ($text, $callback) => str_contains($text, rtrim($url, '/').'/platform/activate#email='))->once();
            } else {
                Mail::shouldNotHaveReceived('raw');
            }
        }
        config(['user-access.frontend_url' => 'http://localhost:8000', 'platform.frontend_url' => 'http://localhost:8000',
            'app.env' => 'local', 'platform.allow_local_http' => true]);
        $this->assertFalse(app(PlatformReadiness::class)->inspect()['local_configuration_checks']['trusted_https_origins']);
    }
}
