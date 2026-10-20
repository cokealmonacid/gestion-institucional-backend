<?php

namespace Modules\Institution\Services;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use App\Platform\Models\PlatformOperator;
use Modules\Documents\Services\DocumentEventRecorder;
use Modules\Documents\Services\DocumentResponsibilityWriter;
use Modules\Institution\Exceptions\RoleChangeException;

class InstitutionalRoleAssignment
{
    // Caller holds the institution/user locks and checks final membership quotas.
    public function replace(User $target, RoleType $role, User|PlatformOperator $actor, array $confirmation): void
    {
        $impact = app(RoleChangeImpact::class);
        $documents = collect();
        if ($impact->losesEligibility($target, $role)) {
            $documents = $impact->affectedDocuments($target, lock: true);
            if (! ($confirmation['confirm_responsibility_removal'] ?? false) || ! isset($confirmation['role_impact_token'])) {
                throw new RoleChangeException('ROLE_CHANGE_IMPACT_CONFIRMATION_REQUIRED', 'Review and confirm the responsibility impact before changing this role.', 409);
            }
            if (! $impact->tokenMatches($target, $role, $documents, $confirmation['role_impact_token'])) {
                throw new RoleChangeException('ROLE_CHANGE_IMPACT_STALE', 'The responsibility impact has changed. Review it again before confirming.', 409);
            }
        }
        $target->roles()->sync([Rol::where('type', $role)->firstOrFail()->id]);
        foreach ($documents as $document) {
            app(DocumentResponsibilityWriter::class)->write($document, null, $actor, app(DocumentEventRecorder::class), DocumentResponsibilityWriter::ROLE_CHANGE_REASON);
        }
    }
}
