<?php

namespace App\Jobs;

use App\Mail\PasswordRecoveryMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Throwable;

class SendPasswordRecovery implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    /** @var array<int, int> */
    public array $backoff;

    public function __construct(
        public readonly string $userId,
        public readonly string $token,
    ) {
        $this->tries = config('user-access.mail.tries', 3);
        $this->backoff = array_fill(0, max(1, $this->tries - 1), config('user-access.mail.backoff_seconds', 60));
    }

    public function handle(): void
    {
        $user = User::query()->with('institution')->find($this->userId);

        if (! $this->eligible($user) || ! Password::broker()->tokenExists($user, $this->token)) {
            return;
        }

        try {
            if (config('mail.default') === 'log') {
                throw new RuntimeException('Access-link email cannot use the log mailer.');
            }

            Mail::to($user->email)->send(new PasswordRecoveryMail(
                $user->name,
                rtrim((string) config('user-access.frontend_url'), '/')
                    .'/recuperar-contrasena?email='.rawurlencode($user->email).'&token='.rawurlencode($this->token),
                (int) config('auth.passwords.users.expire', 60),
            ));
        } catch (Throwable) {
            throw new RuntimeException('The password recovery email transport failed.');
        }
    }

    private function eligible(?User $user): bool
    {
        return $user !== null
            && $user->active
            && $user->email_verified_at !== null
            && $user->institution?->status === true;
    }
}
