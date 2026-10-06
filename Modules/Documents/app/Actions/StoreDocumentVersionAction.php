<?php

namespace Modules\Documents\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Documents\Enums\DocumentEventType;
use Modules\Documents\Exceptions\DocumentVersionCreationException;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentVersion;
use Modules\Documents\Services\DocumentEventRecorder;

class StoreDocumentVersionAction
{
    public function execute(
        User $actor,
        Document $document,
        UploadedFile $file,
        DocumentEventRecorder $events,
    ): DocumentVersion {
        $versionId = (string) Str::uuid();
        $filename = $this->safeFilename($file->getClientOriginalName());
        $extension = $file->getClientOriginalExtension();
        $storedFilename = $versionId.($extension ? '.'.strtolower($extension) : '');
        $path = 'institutions/'.$document->institution_id
            .'/documents/'.$document->id
            .'/versions/'.$versionId
            .'/'.$storedFilename;
        $disk = $this->storageDisk();

        try {
            $stored = $file->storeAs(dirname($path), basename($path), $disk);
        } catch (\Throwable) {
            throw new DocumentVersionCreationException(
                'DOCUMENT_STORAGE_FAILED',
                'The document file could not be stored.',
                500,
            );
        }

        if (! $stored) {
            throw new DocumentVersionCreationException(
                'DOCUMENT_STORAGE_FAILED',
                'The document file could not be stored.',
                500,
            );
        }

        try {
            $storedFileIsValid = Storage::disk($disk)->exists($stored)
                && Storage::disk($disk)->size($stored) > 0;
        } catch (\Throwable) {
            $this->deleteStoredFile($disk, $stored);

            throw new DocumentVersionCreationException(
                'DOCUMENT_STORAGE_FAILED',
                'The stored document file could not be verified.',
                500,
            );
        }

        if (! $storedFileIsValid) {
            $this->deleteStoredFile($disk, $stored);

            throw new DocumentVersionCreationException(
                'DOCUMENT_STORAGE_FAILED',
                'The stored document file could not be verified.',
                500,
            );
        }

        try {
            $version = DB::transaction(function () use ($actor, $document, $file, $versionId, $filename, $stored, $events) {
                $lockedDocument = Document::query()
                    ->whereKey($document->id)
                    ->where('institution_id', $document->institution_id)
                    ->where('status', true)
                    ->whereNotNull('node_id')
                    ->lockForUpdate()
                    ->first();

                if (! $lockedDocument) {
                    return null;
                }

                $versions = DocumentVersion::query()
                    ->where('document_id', $lockedDocument->id)
                    ->where('institution_id', $lockedDocument->institution_id);
                $nextVersionNumber = ((int) (clone $versions)->max('version_number')) + 1;

                (clone $versions)->update(['is_current' => false]);

                $version = new DocumentVersion;
                $version->id = $versionId;
                $version->fill([
                    'version_number' => $nextVersionNumber,
                    'url' => $stored,
                    'filename' => $filename,
                    'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'comment' => null,
                    'author_id' => $actor->id,
                    'document_id' => $lockedDocument->id,
                    'institution_id' => $lockedDocument->institution_id,
                    'node_id' => $lockedDocument->node_id,
                    'active' => true,
                    'is_current' => true,
                ]);
                $version->save();

                $events->record($lockedDocument, DocumentEventType::VersionUploaded, $actor, [
                    'version' => DocumentEventRecorder::versionSnapshot($version),
                    'became_current' => true,
                ], 'document_version', $version->id, $version, $version->created_at);

                return $version;
            });
        } catch (\Throwable $exception) {
            $this->deleteStoredFile($disk, $stored);
            report($exception);

            throw new DocumentVersionCreationException(
                'DOCUMENT_VERSION_CREATION_FAILED',
                'The document version could not be created.',
                500,
            );
        }

        if (! $version) {
            $this->deleteStoredFile($disk, $stored);

            throw new DocumentVersionCreationException(
                'DOCUMENT_NOT_AVAILABLE',
                'The document is not available.',
                404,
            );
        }

        return $version;
    }

    public function storageDisk(): string
    {
        return config('documents.storage_disk') ?: config('filesystems.default');
    }

    public function deleteStoredFile(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable) {
            // Best-effort compensation; an external storage failure may leave an orphaned file.
        }
    }

    private function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $filename));

        if ($filename === '') {
            return 'document';
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $suffix = $extension !== '' ? '.'.$extension : '';

        return mb_substr($basename, 0, max(1, 240 - mb_strlen($suffix))).$suffix;
    }
}
