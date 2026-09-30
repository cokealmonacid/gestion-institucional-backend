<?php

namespace Modules\Documents\Services;

use App\Models\User;
use Modules\Documents\Enums\DocumentEventType;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentResponsibleHistory;

class DocumentResponsibilityWriter
{
    public const ROLE_CHANGE_REASON = 'role_change';

    public function write(
        Document $document,
        ?User $responsible,
        User $actor,
        DocumentEventRecorder $events,
        ?string $reason = null,
    ): Document {
        $previous = $document->responsibleUser()->first();
        $revision = (int) $document->responsibility_revision + 1;
        $document->responsible_user_id = $responsible?->id;
        $document->responsibility_revision = $revision;
        $document->save();

        $history = DocumentResponsibleHistory::create([
            'document_id' => $document->id,
            'previous_responsible_user_id' => $previous?->id,
            'new_responsible_user_id' => $responsible?->id,
            'actor_user_id' => $actor->id,
            'previous_responsible_name' => $previous?->name,
            'new_responsible_name' => $responsible?->name,
            'revision' => $revision,
        ]);

        $type = $previous === null
            ? DocumentEventType::ResponsibleAssigned
            : ($responsible === null
                ? DocumentEventType::ResponsibleRemoved
                : DocumentEventType::ResponsibleChanged);
        $detail = [
            'previous_responsible_name' => $previous?->name,
            'new_responsible_name' => $responsible?->name,
        ];
        if ($type === DocumentEventType::ResponsibleRemoved && $reason !== null) {
            $detail['reason'] = $reason;
        }
        $events->record(
            $document,
            $type,
            $actor,
            $detail,
            'document_responsibility',
            $history->id,
            occurredAt: $history->created_at,
        );

        return $document;
    }
}
