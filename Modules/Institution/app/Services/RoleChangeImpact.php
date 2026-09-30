<?php

namespace Modules\Institution\Services;

use App\Enums\RoleType;
use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Documents\Models\Document;

class RoleChangeImpact
{
    /** @return array{affected_documents_count: int, confirmation_required: bool, impact_token: string|null} */
    public function inspect(User $user, RoleType $requestedRole): array
    {
        $documents = $this->affectedDocuments($user);
        $confirmationRequired = $this->losesEligibility($user, $requestedRole);

        return [
            'affected_documents_count' => $confirmationRequired ? $documents->count() : 0,
            'confirmation_required' => $confirmationRequired,
            'impact_token' => $confirmationRequired
                ? $this->token($user, $requestedRole, $documents)
                : null,
        ];
    }

    /** @param Collection<int, Document> $documents */
    public function tokenMatches(User $user, RoleType $requestedRole, Collection $documents, string $token): bool
    {
        return hash_equals($this->token($user, $requestedRole, $documents), $token);
    }

    public function losesEligibility(User $user, RoleType $requestedRole): bool
    {
        return $this->isEligible($user) && ! in_array($requestedRole, [RoleType::Admin, RoleType::Editor], true);
    }

    public function isEligible(User $user): bool
    {
        return $user->roles()->whereIn('type', [RoleType::Admin->value, RoleType::Editor->value])->exists();
    }

    /** @return Collection<int, Document> */
    public function affectedDocuments(User $user, bool $lock = false): Collection
    {
        $query = Document::query()
            ->where('institution_id', $user->institution_id)
            ->where('responsible_user_id', $user->id)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /** @param Collection<int, Document> $documents */
    private function token(User $user, RoleType $requestedRole, Collection $documents): string
    {
        $roles = $user->roles()->pluck('type')->map(
            fn (mixed $role): string => $role instanceof RoleType ? $role->value : (string) $role,
        )->sort()->values()->all();
        $documentState = $documents->map(
            fn (Document $document): array => [$document->id, (int) $document->responsibility_revision],
        )->values()->all();
        $payload = json_encode([
            'institution_id' => $user->institution_id,
            'user_id' => $user->id,
            'requested_role' => $requestedRole->value,
            'roles' => $roles,
            'documents' => $documentState,
        ], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, (string) config('app.key'), true);

        return rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
