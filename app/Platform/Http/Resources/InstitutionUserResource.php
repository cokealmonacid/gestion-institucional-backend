<?php

namespace App\Platform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstitutionUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $invitation = $this->invitation;

        return [
            'user_id' => $this->id, 'name' => $this->name, 'email' => $this->email,
            'institution_id' => $this->institution_id, 'institution_name' => $this->institution->name,
            'roles' => $this->roles->map(fn ($role) => $role->type->value)->unique()->sort()->values()->all(),
            'active' => (bool) $this->active, 'email_verified' => $this->email_verified_at !== null,
            'access_revision' => (int) $this->access_revision,
            'invitation' => $invitation ? ['delivery_status' => $invitation->delivery_status,
                'expires_at' => $invitation->expires_at?->toISOString(),
                'revoked_at' => $invitation->revoked_at?->toISOString(),
                'consumed_at' => $invitation->consumed_at?->toISOString()] : null,
        ];
    }
}
