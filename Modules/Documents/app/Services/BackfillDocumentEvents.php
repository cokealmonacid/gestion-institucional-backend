<?php

namespace Modules\Documents\Services;

use Illuminate\Support\Facades\DB;
use Modules\Documents\Enums\DocumentEventType;

class BackfillDocumentEvents
{
    /** @return array{documents: int, versions: int, responsibilities: int} */
    public function execute(): array
    {
        return [
            'documents' => $this->documents(),
            'versions' => $this->versions(),
            'responsibilities' => $this->responsibilities(),
        ];
    }

    private function documents(): int
    {
        $inserted = 0;

        DB::table('documents')
            ->leftJoin('users as actors', 'actors.id', '=', 'documents.author_id')
            ->whereNotNull('documents.created_at')
            ->select([
                'documents.id', 'documents.institution_id', 'documents.author_id',
                'documents.created_at', 'actors.institution_id as actor_institution_id',
            ])
            ->orderBy('documents.id')
            ->chunk(500, function ($rows) use (&$inserted): void {
                foreach ($rows as $row) {
                    $actorIsValid = $row->author_id !== null
                        && (string) $row->actor_institution_id === (string) $row->institution_id;
                    $inserted += $this->insertIfMissing([
                        'id' => $this->deterministicId('document', $row->id),
                        'document_id' => $row->id,
                        'institution_id' => $row->institution_id,
                        'type' => DocumentEventType::Created->value,
                        'actor_user_id' => $actorIsValid ? $row->author_id : null,
                        'actor_name' => null,
                        'version_id' => null,
                        'detail' => '{}',
                        'origin' => 'legacy',
                        'source_type' => 'document',
                        'source_id' => $row->id,
                        'occurred_at' => $row->created_at,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->created_at,
                    ]);
                }
            });

        return $inserted;
    }

    private function versions(): int
    {
        $inserted = 0;

        DB::table('document_versions as versions')
            ->join('documents', 'documents.id', '=', 'versions.document_id')
            ->leftJoin('users as actors', 'actors.id', '=', 'versions.author_id')
            ->whereNotNull('versions.created_at')
            ->select([
                'versions.id', 'versions.document_id', 'versions.version_number', 'versions.filename',
                'versions.author_id', 'versions.created_at', 'documents.institution_id',
                'actors.institution_id as actor_institution_id',
            ])
            ->orderBy('versions.id')
            ->chunk(500, function ($rows) use (&$inserted): void {
                foreach ($rows as $row) {
                    $actorIsValid = $row->author_id !== null
                        && (string) $row->actor_institution_id === (string) $row->institution_id;
                    $inserted += $this->insertIfMissing([
                        'id' => $this->deterministicId('document_version', $row->id),
                        'document_id' => $row->document_id,
                        'institution_id' => $row->institution_id,
                        'type' => DocumentEventType::VersionUploaded->value,
                        'actor_user_id' => $actorIsValid ? $row->author_id : null,
                        'actor_name' => null,
                        'version_id' => $row->id,
                        'detail' => json_encode([
                            'version' => [
                                'id' => $row->id,
                                'version_number' => (int) $row->version_number,
                                'filename' => $row->filename,
                            ],
                            'became_current' => null,
                        ], JSON_THROW_ON_ERROR),
                        'origin' => 'legacy',
                        'source_type' => 'document_version',
                        'source_id' => $row->id,
                        'occurred_at' => $row->created_at,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->created_at,
                    ]);
                }
            });

        return $inserted;
    }

    private function responsibilities(): int
    {
        $inserted = 0;

        DB::table('document_responsible_histories as histories')
            ->join('documents', 'documents.id', '=', 'histories.document_id')
            ->leftJoin('users as actors', 'actors.id', '=', 'histories.actor_user_id')
            ->whereNotNull('histories.created_at')
            ->select([
                'histories.id', 'histories.document_id', 'histories.previous_responsible_user_id',
                'histories.new_responsible_user_id', 'histories.previous_responsible_name',
                'histories.new_responsible_name', 'histories.actor_user_id', 'histories.created_at',
                'documents.institution_id', 'actors.institution_id as actor_institution_id',
            ])
            ->orderBy('histories.id')
            ->chunk(500, function ($rows) use (&$inserted): void {
                foreach ($rows as $row) {
                    $type = $this->responsibilityType($row);
                    if ($type === null) {
                        continue;
                    }
                    $actorIsValid = $row->actor_user_id !== null
                        && (string) $row->actor_institution_id === (string) $row->institution_id;
                    $inserted += $this->insertIfMissing([
                        'id' => $this->deterministicId('document_responsibility', $row->id),
                        'document_id' => $row->document_id,
                        'institution_id' => $row->institution_id,
                        'type' => $type->value,
                        'actor_user_id' => $actorIsValid ? $row->actor_user_id : null,
                        'actor_name' => null,
                        'version_id' => null,
                        'detail' => json_encode([
                            'previous_responsible_name' => $row->previous_responsible_name,
                            'new_responsible_name' => $row->new_responsible_name,
                        ], JSON_THROW_ON_ERROR),
                        'origin' => 'legacy',
                        'source_type' => 'document_responsibility',
                        'source_id' => $row->id,
                        'occurred_at' => $row->created_at,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->created_at,
                    ]);
                }
            });

        return $inserted;
    }

    private function responsibilityType(object $row): ?DocumentEventType
    {
        if ($row->previous_responsible_user_id === null && $row->new_responsible_user_id !== null) {
            return DocumentEventType::ResponsibleAssigned;
        }
        if ($row->previous_responsible_user_id !== null && $row->new_responsible_user_id === null) {
            return DocumentEventType::ResponsibleRemoved;
        }
        if ($row->previous_responsible_user_id !== null && $row->new_responsible_user_id !== null) {
            return DocumentEventType::ResponsibleChanged;
        }

        return null;
    }

    private function deterministicId(string $sourceType, string $sourceId): string
    {
        $hex = substr(hash('sha256', "document-events\0{$sourceType}\0{$sourceId}"), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }

    /**
     * Insert one proven legacy event unless its stable source identity already exists.
     *
     * This intentionally avoids insertOrIgnore: only an existing source identity is
     * treated as idempotent; truncation, constraint and other database errors surface.
     *
     * @param  array<string, mixed>  $event
     */
    private function insertIfMissing(array $event): int
    {
        $exists = DB::table('document_events')
            ->where('source_type', $event['source_type'])
            ->where('source_id', $event['source_id'])
            ->exists();

        if ($exists) {
            return 0;
        }

        DB::table('document_events')->insert($event);

        return 1;
    }
}
