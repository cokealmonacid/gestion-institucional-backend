<?php

namespace App\Http\Resources;

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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'institution' => [
                'id' => $this->institution->id,
                'name' => $this->institution->name,
            ],
            'roles' => $this->roles
                ->pluck('type')
                ->map(fn (mixed $role): string => $role instanceof BackedEnum ? $role->value : (string) $role)
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
