<?php

namespace App\Platform\Jobs;

use App\Platform\Models\PlatformAccessRecovery;
use App\Platform\Services\PlatformAccessOrigin;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendInstitutionRecoveryProof implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $operationId, public readonly int $generation, public readonly string $proof) {}

    public function backoff(): array
    {
        return [60, 60];
    }

    public function handle(): void
    {
        $operation = PlatformAccessRecovery::find($this->operationId);
        if (! $operation || $operation->state !== 'awaiting_verification' || $operation->generation !== $this->generation
            || $operation->expires_at->lte(now()) || ! $operation->verification_hash || ! Hash::check($this->proof, $operation->verification_hash)) {
            return;
        }
        try {
            $origin = config('platform.frontend_url');
            if (config('mail.default') === 'log' || ! PlatformAccessOrigin::allowed($origin)) {
                throw new RuntimeException('Access transport unavailable.');
            }
            $setup = $operation->kind === 'replacement' && ! isset($operation->request['successor_id']);
            $url = rtrim($origin, '/').'/platform/access-verification#operation='.$operation->id.'&token='.rawurlencode($this->proof).'&setup='.($setup ? '1' : '0');
            Mail::raw("Acervo: verifica este correo en un plazo de 60 minutos. Esta verificación no inicia sesión ni habilita acceso institucional. Un operador debe completar la recuperación.\n".$url,
                fn ($message) => $message->to($operation->snapshot['successor_email'])->subject('Acervo: verificación de recuperación institucional'));
            PlatformAccessRecovery::whereKey($operation->id)->where('generation', $this->generation)->update(['publication_status' => 'accepted', 'published_at' => now()]);
        } catch (Throwable) {
            $this->failed();
            throw new RuntimeException('Recovery proof publication failed.');
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        try {
            PlatformAccessRecovery::whereKey($this->operationId)->where('generation', $this->generation)->update(['publication_status' => 'failed']);
        } catch (Throwable) { /* Never expose transport/proof data. */
        }
    }
}
