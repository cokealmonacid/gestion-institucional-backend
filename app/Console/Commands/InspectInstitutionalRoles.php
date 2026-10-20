<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class InspectInstitutionalRoles extends Command
{
    protected $signature = 'platform:inspect-institutional-roles {--institution= : Limit to one institution UUID}';

    protected $description = 'Read-only JSON-lines inventory of institutional users with multiple roles; never repairs records';

    public function handle(): int
    {
        $query = User::withTrashed()->with(['roles', 'institution:id,name'])->has('roles', '>', 1)->orderBy('id');
        if ($this->option('institution')) {
            $query->where('institution_id', $this->option('institution'));
        }
        foreach ($query->lazy(100) as $user) {
            $this->line(json_encode(['user_id' => $user->id, 'institution_id' => $user->institution_id,
                'institution_name' => $user->institution?->name, 'name' => $user->name,
                'active' => $user->active, 'deleted' => $user->trashed(), 'roles' => $user->roles->pluck('type')->map(fn ($role) => $role->value)->sort()->values()->all()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
