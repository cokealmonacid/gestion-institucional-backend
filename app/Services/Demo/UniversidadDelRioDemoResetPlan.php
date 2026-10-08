<?php

namespace App\Services\Demo;

use App\Models\Rol;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Actions\StoreDocumentVersionAction;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentVersion;
use Modules\Institution\Models\Institution;
use Modules\Nodes\Models\Node;
use RuntimeException;

class UniversidadDelRioDemoResetPlan
{
    public const TTL_MINUTES = 15;

    public function __construct(private readonly StoreDocumentVersionAction $versions) {}

    /** @return array<string, mixed> */
    public function preview(): array
    {
        $plan = $this->inventory();
        $expiresAt = CarbonImmutable::now()->addMinutes(self::TTL_MINUTES);
        $plan['expires_at'] = $expiresAt->toIso8601String();
        $plan['token'] = $this->sign($plan['inventory_hash'], $expiresAt);

        return $plan;
    }

    /** @return array{inventory_hash: string, expires_at: CarbonImmutable} */
    public function validateToken(string $token): array
    {
        [$encoded, $providedSignature] = array_pad(explode('.', $token, 2), 2, null);
        if (! is_string($encoded) || ! is_string($providedSignature)) {
            throw new RuntimeException('El token del plan no es válido.');
        }
        $expectedSignature = $this->signature($encoded);
        if (! hash_equals($expectedSignature, $providedSignature)) {
            throw new RuntimeException('El token del plan no es válido.');
        }
        $payload = json_decode($this->base64UrlDecode($encoded), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)
            || ($payload['institution_id'] ?? null) !== UniversidadDelRioDemoLoader::INSTITUTION_ID
            || ! is_string($payload['inventory_hash'] ?? null)
            || ! is_string($payload['expires_at'] ?? null)) {
            throw new RuntimeException('El token del plan no es válido.');
        }
        $expiresAt = CarbonImmutable::parse($payload['expires_at']);
        if ($expiresAt->isPast()) {
            throw new RuntimeException('El token del plan caducó. Genere una nueva previsualización.');
        }

        return ['inventory_hash' => $payload['inventory_hash'], 'expires_at' => $expiresAt];
    }

    /** @return array<string, mixed> */
    public function inventory(bool $lock = false): array
    {
        $institutionQuery = Institution::query()->whereKey(UniversidadDelRioDemoLoader::INSTITUTION_ID);
        if ($lock) {
            $institutionQuery->lockForUpdate();
        }
        $institution = $institutionQuery->first();
        if (! $institution || $institution->name !== 'Universidad del Río') {
            throw new RuntimeException('No se encontró la institución de demostración con su identidad estable.');
        }

        $users = $this->validatedUsers($lock);
        $nodesQuery = Node::query()->where('institution_id', $institution->id)->orderBy('id');
        $documentsQuery = Document::query()->where('institution_id', $institution->id)->orderBy('id');
        if ($lock) {
            $nodesQuery->lockForUpdate();
            $documentsQuery->lockForUpdate();
        }
        $nodes = $nodesQuery->get();
        $documents = $documentsQuery->get();
        $documentIds = $documents->pluck('id')->all();
        $versionsQuery = DocumentVersion::query()->where('institution_id', $institution->id)->orderBy('id');
        if ($lock) {
            $versionsQuery->lockForUpdate();
        }
        $versions = $versionsQuery->get();
        $versionIds = $versions->pluck('id')->all();

        $files = $this->fileCandidates($versions, $documentIds);
        $rows = [
            'institution' => [$this->attributes($institution)],
            'users' => $users->map(fn (User $user): array => [
                ...$this->attributes($user),
                'roles' => $user->roles->map(fn (Rol $role): string => $role->type->value)->sort()->values()->all(),
            ])->all(),
            'nodes' => $nodes->map(fn (Node $node): array => $this->attributes($node))->all(),
            'documents' => $documents->map(fn (Document $document): array => $this->attributes($document))->all(),
            'document_versions' => $versions->map(fn (DocumentVersion $version): array => $this->attributes($version))->all(),
            'document_downloads' => $this->tableRows('document_downloads', 'document_id', $documentIds),
            'document_tags' => $this->tableRows('document_tags', 'document_id', $documentIds),
            'document_events' => $this->tableRows('document_events', 'document_id', $documentIds),
            'document_responsible_histories' => $this->tableRows('document_responsible_histories', 'document_id', $documentIds),
            'document_version_comment_histories' => $this->tableRows('document_version_comment_histories', 'document_id', $documentIds),
        ];
        $inventoryHash = hash('sha256', json_encode([
            'rows' => $rows,
            'files' => $files,
        ], JSON_THROW_ON_ERROR));

        return [
            'institution_id' => $institution->id,
            'inventory_hash' => $inventoryHash,
            'counts' => array_map('count', $rows),
            'accounts' => $users->map(fn (User $user): array => [
                'id' => $user->id,
                'email' => $user->email,
                'roles' => $user->roles->map(fn (Rol $role): string => $role->type->value)->sort()->values()->all(),
            ])->values()->all(),
            'records' => [
                'nodes' => $nodes->map->only(['id', 'name'])->values()->all(),
                'documents' => $documents->map->only(['id', 'name'])->values()->all(),
                'versions' => $versions->map->only(['id', 'document_id', 'version_number', 'filename', 'url'])->values()->all(),
            ],
            'files' => $files,
        ];
    }

    private function sign(string $inventoryHash, CarbonImmutable $expiresAt): string
    {
        $payload = $this->base64UrlEncode(json_encode([
            'institution_id' => UniversidadDelRioDemoLoader::INSTITUTION_ID,
            'inventory_hash' => $inventoryHash,
            'expires_at' => $expiresAt->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->signature($payload);
    }

    private function signature(string $payload): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $payload, (string) config('app.key'), true));
    }

    /** @return Collection<int, User> */
    private function validatedUsers(bool $lock)
    {
        $definitions = UniversidadDelRioDemoLoader::userDefinitions();
        $query = User::query()->where('institution_id', UniversidadDelRioDemoLoader::INSTITUTION_ID)->with('roles')->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $users = $query->get();
        if ($users->count() !== count($definitions)) {
            throw new RuntimeException('El reset requiere exactamente las tres cuentas estables de la demostración.');
        }
        foreach ($definitions as $definition) {
            $user = $users->firstWhere('id', $definition['id']);
            $roles = $user?->roles->map(fn (Rol $role): string => $role->type->value)->sort()->values()->all();
            if (! $user
                || $user->email !== $definition['email']
                || $user->name !== $definition['name']
                || ! $user->active
                || $user->email_verified_at === null
                || $roles !== [$definition['role']->value]) {
                throw new RuntimeException("La cuenta estable {$definition['email']} está ausente o es incompatible.");
            }
        }

        return $users;
    }

    /** @param Collection<int, DocumentVersion> $versions @param list<string> $documentIds */
    private function fileCandidates($versions, array $documentIds): array
    {
        $disk = Storage::disk($this->versions->storageDisk());

        return $versions->groupBy('url')->map(function ($samePath) use ($disk, $documentIds): array {
            /** @var DocumentVersion $version */
            $version = $samePath->first();
            $path = (string) $version->url;
            $expectedPrefix = 'institutions/'.UniversidadDelRioDemoLoader::INSTITUTION_ID
                .'/documents/'.$version->document_id.'/versions/'.$version->id.'/';
            $shared = DocumentVersion::query()
                ->where('url', $path)
                ->whereNotIn('document_id', $documentIds)
                ->exists();
            try {
                $exists = $path !== '' && $disk->exists($path);
                $size = $exists ? $disk->size($path) : null;
                $readError = false;
            } catch (\Throwable) {
                $exists = false;
                $size = null;
                $readError = true;
            }
            $reason = $readError
                ? 'unreadable'
                : (! str_starts_with($path, $expectedPrefix)
                    ? 'outside_expected_prefix'
                    : ($shared ? 'shared' : ($exists ? 'eligible' : 'missing')));

            return [
                'path' => $path,
                'version_ids' => $samePath->pluck('id')->sort()->values()->all(),
                'eligible' => $reason === 'eligible',
                'reason' => $reason,
                'exists' => $exists,
                'size' => $size,
            ];
        })->sortBy('path')->values()->all();
    }

    /** @param list<string> $ids */
    private function tableRows(string $table, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($table)->whereIn($column, $ids)->orderBy('id')->get()
            ->map(fn (object $row): array => $this->sorted((array) $row))->all();
    }

    private function attributes(object $model): array
    {
        /** @var Model $model */
        return $this->sorted($model->getAttributes());
    }

    private function sorted(array $value): array
    {
        ksort($value);

        return $value;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('El token del plan no es válido.');
        }

        return $decoded;
    }
}
