<?php

namespace App\Platform\Jobs;

use App\Platform\Models\PlatformAccessLink;
use App\Platform\Models\PlatformOperator;
use App\Platform\Services\PlatformAccessOrigin;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendPlatformAccessLink implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public function backoff(): array
    {
        return [60, 60];
    }

    public function __construct(public readonly string $linkId, public readonly int $generation, public readonly string $token) {}

    public function handle(): void
    {
        $link = PlatformAccessLink::find($this->linkId);
        $operator = $link ? PlatformOperator::find($link->operator_id) : null;
        if (! $operator?->active || ! $link?->token_hash || $link->generation !== $this->generation || $link->consumed_at || $link->expires_at->lte(now()) || ! Hash::check($this->token, $link->token_hash) || ($link->purpose === 'activation' && $operator->verified_at)) {
            return;
        }
        try {
            $origin = config('platform.frontend_url');
            if (config('mail.default') === 'log' || ! PlatformAccessOrigin::allowed($origin)) {
                throw new RuntimeException('Access transport unavailable.');
            }
            // Fragments never reach HTTP access logs or the server-rendered shell.
            $url = rtrim($origin, '/').'/platform/'.($link->purpose === 'activation' ? 'activate' : 'password-reset').'#email='.rawurlencode($operator->email).'&token='.rawurlencode($this->token);
            Mail::raw("Use this single-use Acervo platform access link within 60 minutes:\n".$url, fn ($message) => $message->to($operator->email)->subject('Acervo platform access'));
            PlatformAccessLink::whereKey($link->id)->where('generation', $this->generation)->whereNotNull('token_hash')->update(['publication_status' => 'accepted']);
        } catch (Throwable) {
            $this->failed();
            throw new RuntimeException('Platform access publication failed.');
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        try {
            PlatformAccessLink::whereKey($this->linkId)->where('generation', $this->generation)->update(['publication_status' => 'failed']);
        } catch (Throwable) { /* A reconciliation retry can diagnose unknown publication; never expose transport context. */
        }
    }
}
