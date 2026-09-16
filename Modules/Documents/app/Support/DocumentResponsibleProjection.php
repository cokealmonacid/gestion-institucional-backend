<?php

namespace Modules\Documents\Support;

use LogicException;
use Modules\Documents\Models\Document;

class DocumentResponsibleProjection
{
    /** @return array{id: string, name: string, active: bool}|null */
    public static function for(Document $document): ?array
    {
        if (! $document->relationLoaded('responsibleUser')) {
            throw new LogicException('The responsible user relationship must be loaded before projection.');
        }

        $responsible = $document->responsibleUser;

        if (! $responsible || (string) $responsible->institution_id !== (string) $document->institution_id) {
            return null;
        }

        return [
            'id' => $responsible->id,
            'name' => $responsible->name,
            'active' => (bool) $responsible->active && ! $responsible->trashed(),
        ];
    }
}
