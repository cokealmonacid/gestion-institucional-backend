<?php

namespace App\Platform\Services;

use App\Models\User;
use App\Platform\Models\AdministrativeEvent;
use App\Platform\Models\PlatformOperator;
use Illuminate\Support\Str;

class AdministrativeEventRecorder
{
    public function record(PlatformOperator|User|string $actor, string $action, string $kind, string $id, array $before = [], array $after = [], string $reason = 'Authentication lifecycle', ?string $institution = null, ?string $operation = null): AdministrativeEvent
    {
        // Call sites supply explicit safe values, never model attributes or request payloads.
        $this->assertSafe($before);
        $this->assertSafe($after);

        return AdministrativeEvent::create(['actor_kind' => $actor instanceof PlatformOperator ? 'platform' : ($actor instanceof User ? 'institutional' : 'operator_cli'), 'actor_id' => ($actor instanceof PlatformOperator || $actor instanceof User) ? $actor->id : $actor, 'actor_name' => ($actor instanceof PlatformOperator || $actor instanceof User) ? $actor->name : $actor, 'action' => $action, 'subject_kind' => $kind, 'subject_id' => $id, 'institution_id' => $institution, 'before_values' => $before, 'after_values' => $after, 'reason' => $reason, 'operation_id' => $operation ?? (string) Str::uuid(), 'occurred_at' => now()]);
    }

    private function assertSafe(array $values): void
    {
        foreach ($values as $key => $value) {
            if (preg_match('/password|token|secret|recovery_code|provisioning_uri|link/i', (string) $key)) {
                throw new \LogicException('Sensitive audit property.');
            } if (is_array($value)) {
                $this->assertSafe($value);
            }
        }
    }
}
