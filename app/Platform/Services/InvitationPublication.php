<?php

namespace App\Platform\Services;

use App\Models\UserInvitation;
use App\Services\UserAccess\InvitationService;
use Illuminate\Support\Facades\Log;

class InvitationPublication
{
    // Call after the owning transaction returns. Raw tokens live only in this call
    // and the encrypted queue payload; never in operation results or diagnostics.
    public function publish(UserInvitation $invitation, string $plain): array
    {
        $result = ['publication_status' => 'unknown', 'delivery_status' => 'pending'];
        try {
            $published = app(InvitationService::class)->publish($invitation, $plain);
            $result['publication_status'] = $published ? 'queued' : 'failed';
            $result['delivery_status'] = $published ? 'pending' : 'failed';
            $current = $this->current($invitation);
            if (! $current) {
                throw new \RuntimeException('Publication state unavailable.');
            }
            if ($current->generation === $invitation->generation) {
                $result['delivery_status'] = $current->delivery_status;
            }
        } catch (\Throwable) {
            $result['publication_status'] = 'unknown';
            try {
                Log::warning('Invitation publication outcome unavailable.', ['invitation_id' => $invitation->id]);
            } catch (\Throwable) {
            }
        }

        return $result;
    }

    protected function current(UserInvitation $invitation): ?UserInvitation
    {
        return $invitation->fresh();
    }

    public function stale(?UserInvitation $invitation): bool
    {
        return $invitation && $invitation->delivery_status === 'pending'
            && $invitation->last_sent_at?->lte(now()->subMinutes(5));
    }
}
