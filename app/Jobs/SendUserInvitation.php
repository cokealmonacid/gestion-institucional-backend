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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\ViewException;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
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

        $failureCategory = 'mailer_policy';
        try {
            $this->assertSafeMailer();
            $failureCategory = 'mail_delivery';
            Mail::to($invitation->user->email)->send(new UserInvitationMail(
                $invitation->user->name,
                $invitation->user->institution->name,
                $this->activationUrl($invitation->user->email),
                (int) ceil(config('user-access.invitation.expire_minutes', 2880) / 60),
            ));
        } catch (Throwable $exception) {
            if ($exception instanceof ViewException) {
                $failureCategory = 'mail_rendering';
            } elseif ($exception instanceof TransportExceptionInterface) {
                $failureCategory = 'mail_transport';
            }
            $exceptionClass = get_class($exception);
            $rejectionReason = $this->smtpRejectionReason($exception);
            Log::warning('Invitation email failed.', [
                'exception_class' => preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,179}$/D', $exceptionClass)
                    ? $exceptionClass : 'unknown',
                'failure_category' => $failureCategory,
                'failure_code' => is_int($exception->getCode()) ? $exception->getCode() : null,
                ...($rejectionReason === null ? [] : ['smtp_rejection_reason' => $rejectionReason]),
            ]);
            $this->markFailed();
            // Never chain the original exception: queue failure records serialize its
            // message and trace, which may contain SMTP credentials or access links.
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

    private function smtpRejectionReason(Throwable $exception): ?string
    {
        // Only inspect delivery rejections, never authentication errors or debug transcripts.
        if (! $exception instanceof UnexpectedResponseException
            || ! in_array($exception->getCode(), [450, 451, 452, 550, 551, 552, 553, 554], true)) {
            return null;
        }

        if (! preg_match('/\AExpected response code "[0-9\/]+" but got code "[0-9]{3}", with message "(.*)"\.\z/sD', $exception->getMessage(), $matches)) {
            return 'other_rejection';
        }

        $response = strtolower($matches[1]);
        // Emit only fixed labels. Never log any substring of the provider response.
        if (str_contains($response, 'only send testing emails to your own email address')) {
            return 'test_recipient_restriction';
        }

        if ((str_contains($response, 'domain') && str_contains($response, 'not verified'))
            || str_contains($response, 'sender address rejected')
            || str_contains($response, 'sender domain rejected')) {
            return 'sender_domain_rejection';
        }

        return 'other_rejection';
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
