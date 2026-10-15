<?php

namespace App\Console\Commands;

use App\Models\UserInvitation;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ReconcileInvitationPublication extends Command
{
    protected $signature = 'platform:invitations:reconcile {--limit=100} {--after= : UUID keyset cursor}';

    protected $description = 'Read-only stale pending publication report; prints safe identifiers and never sends mail';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $after = $this->option('after');
        if (! $limit || $limit < 1 || $limit > 1000 || ($after !== null && ! Str::isUuid($after))) {
            $this->error('Use limit 1..1000 and an optional UUID cursor.');

            return self::FAILURE;
        }
        try {
            $query = UserInvitation::where('delivery_status', 'pending')->where('last_sent_at', '<=', now()->subMinutes(5))
                ->whereNull('consumed_at')->whereNull('revoked_at')->whereNotNull('token_hash')
                ->whereHas('user', fn ($q) => $q->where('active', true)->whereNull('email_verified_at')->whereHas('institution', fn ($i) => $i->where('status', true)))
                ->with(['user:id,institution_id', 'user.roles:id,type', 'user.institution:id,auth_epoch']);
            if ($after) {
                $query->where('id', '>', strtolower($after));
            }
            $rows = $query->orderBy('id')->limit($limit + 1)->get();
            $items = $rows->take($limit)->filter(fn ($i) => (int) $i->institution_auth_epoch === (int) $i->user->institution->auth_epoch)->map(fn ($i) => [
                'institution_id' => $i->user->institution_id, 'user_id' => $i->user_id, 'invitation_id' => $i->id, 'generation' => $i->generation,
                'publication_status' => 'unknown', 'expired' => $i->expires_at->isPast(), 'administrator' => $i->user->roles->contains(fn ($r) => $r->type->value === 'admin'),
            ])->values()->all();
            $this->line(json_encode(['read_only' => true, 'items' => $items, 'next_cursor' => $rows->count() > $limit ? $rows->take($limit)->last()->id : null,
                'recovery' => 'Query invitation status and explicitly resend with an authorized platform operator for administrators, or the institutional administrator for other roles. Resend rotates generation; no raw token is recoverable.'], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Read-only invitation inspection could not complete. No publication was attempted.');

            return self::FAILURE;
        }
    }
}
