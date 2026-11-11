<?php

namespace App\Http\Resources;

use App\Authorization\InstitutionAbilityProjection;
use App\Services\OnboardingStateResolver;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $institutionAccess = app(OnboardingStateResolver::class)->hasInstitutionAccess($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'institution' => $this->institution === null ? null : [
                'id' => $this->institution->id,
                'name' => $this->institution->name,
            ],
            'roles' => ! $institutionAccess ? [] : $this->roles
                ->pluck('type')
                ->map(fn (mixed $role): string => $role instanceof BackedEnum ? $role->value : (string) $role)
                ->sort()
                ->values()
                ->all(),
            'abilities' => ! $institutionAccess ? [] : app(InstitutionAbilityProjection::class)->project($this->resource),
        ];
    }
}
