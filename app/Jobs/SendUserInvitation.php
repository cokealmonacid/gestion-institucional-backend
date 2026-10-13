<?php

namespace App\Jobs;

use App\Mail\UserInvitationMail;
use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendUserInvitation implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    /** @var array<int, int> */
    public array $backoff;

    public function __construct(
        public readonly string $invitationId,
        public readonly int $generation,
        public readonly string $token,
    ) {
        $this->tries = config('user-access.mail.tries', 3);
        $this->backoff = array_fill(0, max(1, $this->tries - 1), config('user-access.mail.backoff_seconds', 60));
    }

    public function handle(): void
    {
        $invitation = UserInvitation::query()->with('user.institution')->find($this->invitationId);

        if (! $this->isCurrentAndEligible($invitation)) {
            return;
        }

        $invitation->newQuery()->whereKey($invitation->id)->where('generation', $this->generation)->update([
            'delivery_attempted_at' => now(),
        ]);

        try {
            $this->assertSafeMailer();
            Mail::to($invitation->user->email)->send(new UserInvitationMail(
                $invitation->user->name,
                $invitation->user->institution->name,
                $this->activationUrl($invitation->user->email),
                (int) ceil(config('user-access.invitation.expire_minutes', 2880) / 60),
            ));
        } catch (Throwable) {
            $this->markFailed();
            throw new RuntimeException('The invitation email transport failed.');
        }

        $invitation->newQuery()->whereKey($invitation->id)->where('generation', $this->generation)->update([
            'delivery_status' => 'accepted',
            'provider_accepted_at' => now(),
            'failed_at' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed();
    }

    private function isCurrentAndEligible(?UserInvitation $invitation): bool
    {
        return $invitation !== null
            && $invitation->generation === $this->generation
            && $invitation->token_hash !== null
            && Hash::check($this->token, $invitation->token_hash)
            && $invitation->consumed_at === null
            && $invitation->expires_at->isFuture()
            && $invitation->user !== null
            && $invitation->user->active
            && $invitation->user->email_verified_at === null
            && $invitation->user->institution?->status === true;
    }

    private function activationUrl(string $email): string
    {
        return rtrim((string) config('user-access.frontend_url'), '/')
            .'/activar-cuenta?email='.rawurlencode($email).'&token='.rawurlencode($this->token);
    }

    private function assertSafeMailer(): void
    {
        if (config('mail.default') === 'log') {
            throw new RuntimeException('Access-link email cannot use the log mailer.');
        }
    }

    private function markFailed(): void
    {
        UserInvitation::query()->whereKey($this->invitationId)->where('generation', $this->generation)->update([
            'delivery_status' => 'failed',
            'failed_at' => now(),
        ]);
    }
}
