<?php

namespace App\Services\Demo;

use App\Enums\RoleType;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Actions\CreateDocumentAction;
use Modules\Documents\Actions\StoreDocumentVersionAction;
use Modules\Documents\Actions\UpdateDocumentResponsibilityAction;
use Modules\Documents\Actions\UpdateDocumentVersionNoteAction;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\DocumentEvent;
use Modules\Documents\Models\DocumentResponsibleHistory;
use Modules\Documents\Models\DocumentVersionCommentHistory;
use Modules\Documents\Services\DocumentEventRecorder;
use Modules\Documents\Services\DocumentResponsibilityWriter;
use Modules\Institution\Actions\CreateNodeAction;
use Modules\Institution\Models\Institution;
use Modules\Institution\Services\RoleChangeImpact;
use Modules\Nodes\Models\Node;
use RuntimeException;

class UniversidadDelRioDemoLoader
{
    public const INSTITUTION_ID = '755ab137-825b-5f22-a4e5-ab961fea03d3';

    private const PASSWORD = '12345678';

    private const USERS = [
        'admin' => [
            'id' => '1fa2cbad-9fd0-530d-a8bb-67ca30e0113c',
            'name' => 'Catalina Ríos',
            'email' => 'admin@universidad-del-rio.example',
            'role' => RoleType::Admin,
        ],
        'editor' => [
            'id' => 'f58776e8-8f68-5631-a0bb-c0d8746962d2',
            'name' => 'Martín Vega',
            'email' => 'editor@universidad-del-rio.example',
            'role' => RoleType::Editor,
        ],
        'reader' => [
            'id' => 'f17f2532-fbaa-5ca8-974f-4fe8454539a7',
            'name' => 'Paula Soto',
            'email' => 'lector@universidad-del-rio.example',
            'role' => RoleType::Reader,
        ],
    ];

    private const DOCUMENTS = [
        'Calendario de admisión 2027' => [
            'node' => 'Lineamientos y calendario',
            'description' => 'Hitos ficticios del proceso de admisión 2027.',
            'category' => 'Calendario',
            'unit' => 'Dirección de Admisión',
            'files' => ['calendario-admision-2027-v1.pdf'],
        ],
        'Lineamientos de atención a postulantes' => [
            'node' => 'Lineamientos y calendario',
            'description' => 'Orientaciones ficticias para la atención de postulantes.',
            'category' => 'Lineamiento',
            'unit' => 'Dirección de Admisión',
            'files' => ['lineamientos-atencion-postulantes-v1.pdf'],
        ],
        'Ficha de Ingeniería Civil en Informática' => [
            'node' => 'Oferta académica',
            'description' => 'Ficha ficticia del programa para fines de demostración.',
            'category' => 'Oferta académica',
            'unit' => 'Facultad de Ingeniería',
            'files' => ['ficha-ingenieria-civil-informatica-v1.pdf'],
        ],
        'Ficha de Enfermería' => [
            'node' => 'Oferta académica',
            'description' => 'Ficha ficticia del programa para fines de demostración.',
            'category' => 'Oferta académica',
            'unit' => 'Facultad de Salud',
            'files' => ['ficha-enfermeria-v1.pdf'],
        ],
        'Requisitos de ingreso especial 2027' => [
            'node' => 'Requisitos y vías de ingreso',
            'description' => 'Requisitos ficticios para vías de ingreso especial 2027.',
            'category' => 'Requisitos',
            'unit' => 'Dirección de Admisión',
            'files' => [
                'requisitos-ingreso-especial-2027-v1.pdf',
                'requisitos-ingreso-especial-2027-v2.pdf',
            ],
        ],
    ];

    private const NOTES = [
        1 => 'Versión inicial de los requisitos de ingreso especial 2027.',
        2 => 'Se incorpora el certificado de avance curricular para la vía de traslado. Esta versión reemplaza la anterior para orientar las consultas de la campaña 2027.',
    ];

    public function __construct(
        private readonly CreateNodeAction $createNode,
        private readonly CreateDocumentAction $createDocument,
        private readonly StoreDocumentVersionAction $storeVersion,
        private readonly UpdateDocumentResponsibilityAction $updateResponsibility,
        private readonly UpdateDocumentVersionNoteAction $updateNote,
        private readonly DocumentEventRecorder $events,
        private readonly RoleChangeImpact $roleImpact,
        private readonly DocumentResponsibilityWriter $responsibilityWriter,
    ) {}

    /** @return array{status: string, message: string, details: list<string>} */
    public function inspect(): array
    {
        $institution = Institution::query()->find(self::INSTITUTION_ID);
        $collisions = $this->externalCollisions();

        if (! $institution) {
            return $collisions === []
                ? $this->result('absent', 'El escenario no está cargado.')
                : $this->result('conflict', 'Hay identificadores del escenario ocupados por otros datos.', $collisions);
        }

        if ($institution->name !== 'Universidad del Río') {
            return $this->result('conflict', 'El identificador estable del escenario pertenece a otra institución.');
        }

        if ($collisions !== []) {
            return $this->result('conflict', 'Hay identificadores del escenario ocupados por otros datos.', $collisions);
        }

        $problems = [];
        $missing = [];
        if (! $institution->status) {
            $problems[] = 'La institución fue modificada.';
        }

        $this->inspectUsers($missing, $problems);
        $nodes = $this->inspectNodes($missing, $problems);
        $this->inspectDocuments($nodes, $missing, $problems);

        if ($problems !== []) {
            return $this->result('modified', 'El escenario existe, pero fue modificado.', $problems);
        }
        if ($missing !== []) {
            return $this->result('partial', 'El escenario está incompleto.', $missing);
        }

        return $this->result('complete', 'El escenario está completo e intacto.');
    }

    /** @return array{status: string, message: string, details: list<string>} */
    public function load(): array
    {
        $before = $this->inspect();
        if ($before['status'] === 'complete') {
            return $before;
        }
        if ($before['status'] !== 'absent') {
            throw new RuntimeException($before['message'].' '.implode(' ', $before['details']));
        }

        $roles = Rol::query()->whereIn('type', array_map(
            fn (RoleType $role): string => $role->value,
            RoleType::cases(),
        ))->get()->keyBy(fn (Rol $role): string => $role->type->value);
        foreach (RoleType::cases() as $role) {
            if (! $roles->has($role->value)) {
                throw new RuntimeException("Falta el rol de sistema {$role->value}.");
            }
        }
        $this->assertAssets();

        $createdFiles = [];
        $disk = $this->storeVersion->storageDisk();

        try {
            DB::transaction(function () use ($roles, &$createdFiles): void {
                if ($this->inspect()['status'] !== 'absent') {
                    throw new RuntimeException('El escenario cambió durante la prevalidación. Vuelva a ejecutar --check.');
                }

                $institution = new Institution;
                $institution->id = self::INSTITUTION_ID;
                $institution->fill(['name' => 'Universidad del Río', 'status' => true]);
                $institution->save();

                $users = [];
                foreach (self::USERS as $key => $definition) {
                    $user = new User;
                    $user->id = $definition['id'];
                    $user->fill([
                        'name' => $definition['name'],
                        'email' => $definition['email'],
                        'password' => self::PASSWORD,
                        'active' => true,
                        'institution_id' => $institution->id,
                    ]);
                    $user->email_verified_at = now();
                    $user->save();
                    $user->roles()->attach($roles[$definition['role']->value]->id);
                    $users[$key] = $user;
                }

                $nodes = [];
                $nodes['Admisión'] = $this->createNode->execute($users['admin'], null, 'Admisión');
                $nodes['Admisión 2027'] = $this->createNode->execute($users['admin'], $nodes['Admisión']->id, 'Admisión 2027');
                foreach (['Lineamientos y calendario', 'Oferta académica', 'Requisitos y vías de ingreso'] as $name) {
                    $nodes[$name] = $this->createNode->execute($users['admin'], $nodes['Admisión 2027']->id, $name);
                }

                $documents = [];
                $versions = [];
                foreach (self::DOCUMENTS as $name => $definition) {
                    $document = $this->createDocument->execute($users['editor'], $nodes[$definition['node']]->id, [
                        'name' => $name,
                        'description' => $definition['description'],
                        'category' => $definition['category'],
                        'responsible_unit' => $definition['unit'],
                    ], $this->events);
                    $documents[$name] = $document;

                    foreach ($definition['files'] as $filename) {
                        $version = $this->storeVersion->execute(
                            $users['editor'],
                            $document,
                            $this->uploadedAsset($filename),
                            $this->events,
                        );
                        $createdFiles[] = $version->url;
                        $versions[$name][(int) $version->version_number] = $version;
                    }
                }

                $protagonist = $documents['Requisitos de ingreso especial 2027'];
                $this->updateResponsibility->execute(
                    $users['admin'],
                    $protagonist->id,
                    $users['editor']->id,
                    0,
                    $this->events,
                    $this->roleImpact,
                    $this->responsibilityWriter,
                );
                foreach (self::NOTES as $number => $note) {
                    $this->updateNote->execute(
                        $users['editor'],
                        $protagonist->id,
                        $versions[$protagonist->name][$number]->id,
                        $note,
                        $this->events,
                    );
                }
            }, 3);
        } catch (\Throwable $exception) {
            foreach ($createdFiles as $path) {
                $this->storeVersion->deleteStoredFile($disk, $path);
            }
            throw $exception;
        }

        $after = $this->inspect();
        if ($after['status'] !== 'complete') {
            throw new RuntimeException('La carga terminó, pero la validación posterior no confirmó un escenario completo.');
        }

        return $after;
    }

    /** @param list<string> $missing @param list<string> $problems */
    private function inspectUsers(array &$missing, array &$problems): void
    {
        foreach (self::USERS as $definition) {
            $user = User::with('roles')->find($definition['id']);
            if (! $user) {
                $missing[] = "Falta la cuenta {$definition['email']}.";

                continue;
            }
            $roles = $user->roles->map(fn (Rol $role): string => $role->type->value)->sort()->values()->all();
            if ($user->name !== $definition['name']
                || $user->email !== $definition['email']
                || (string) $user->institution_id !== self::INSTITUTION_ID
                || ! $user->active
                || $user->email_verified_at === null
                || $roles !== [$definition['role']->value]
                || ! Hash::check(self::PASSWORD, $user->password)) {
                $problems[] = "La cuenta {$definition['email']} fue modificada.";
            }
        }
        if (User::query()->where('institution_id', self::INSTITUTION_ID)->count() > count(self::USERS)) {
            $problems[] = 'La institución contiene cuentas adicionales.';
        }
    }

    /** @param list<string> $missing @param list<string> $problems @return array<string, Node> */
    private function inspectNodes(array &$missing, array &$problems): array
    {
        $all = Node::query()->where('institution_id', self::INSTITUTION_ID)->get();
        $expected = [];
        $parent = null;
        foreach ([
            ['Admisión', null],
            ['Admisión 2027', 'Admisión'],
            ['Lineamientos y calendario', 'Admisión 2027'],
            ['Oferta académica', 'Admisión 2027'],
            ['Requisitos y vías de ingreso', 'Admisión 2027'],
        ] as [$name, $parentName]) {
            $parent = $parentName === null ? null : ($expected[$parentName] ?? null);
            $node = $all->first(fn (Node $candidate): bool => $candidate->name === $name
                && (string) $candidate->parent_id === (string) $parent?->id);
            if (! $node) {
                $missing[] = "Falta la carpeta {$name}.";

                continue;
            }
            if (! $node->active) {
                $problems[] = "La carpeta {$name} está inactiva.";
            }
            $expected[$name] = $node;
        }
        if ($all->count() > count($expected)) {
            $problems[] = 'La institución contiene carpetas adicionales o renombradas.';
        }

        return $expected;
    }

    /** @param array<string, Node> $nodes @param list<string> $missing @param list<string> $problems */
    private function inspectDocuments(array $nodes, array &$missing, array &$problems): void
    {
        $all = Document::query()->where('institution_id', self::INSTITUTION_ID)->with(['versions', 'responsibleUser'])->get();
        $matched = 0;
        foreach (self::DOCUMENTS as $name => $definition) {
            $node = $nodes[$definition['node']] ?? null;
            $document = $all->first(fn (Document $candidate): bool => $candidate->name === $name
                && $node && (string) $candidate->node_id === (string) $node->id);
            if (! $document) {
                $missing[] = "Falta el documento {$name}.";

                continue;
            }
            $matched++;
            if (! $document->status
                || $document->description !== $definition['description']
                || $document->category !== $definition['category']
                || $document->responsible_unit !== $definition['unit']) {
                $problems[] = "El documento {$name} fue modificado.";
            }
            $this->inspectVersions($document, $definition['files'], $missing, $problems);
        }
        if ($all->count() > $matched) {
            $problems[] = 'La institución contiene documentos adicionales o renombrados.';
        }

        $protagonist = $all->firstWhere('name', 'Requisitos de ingreso especial 2027');
        if ($protagonist) {
            $editorId = self::USERS['editor']['id'];
            if ((string) $protagonist->responsible_user_id !== $editorId || (int) $protagonist->responsibility_revision !== 1) {
                $problems[] = 'La responsabilidad del documento protagonista fue modificada.';
            }
            if (DocumentResponsibleHistory::where('document_id', $protagonist->id)->count() !== 1
                || DocumentVersionCommentHistory::where('document_id', $protagonist->id)->count() !== 2) {
                $problems[] = 'El historial especializado del documento protagonista fue modificado.';
            }
        }

        if (DocumentEvent::where('institution_id', self::INSTITUTION_ID)->count() !== 14) {
            $problems[] = 'La trazabilidad documental no coincide con la carga inicial.';
        }
    }

    /** @param list<string> $filenames @param list<string> $missing @param list<string> $problems */
    private function inspectVersions(Document $document, array $filenames, array &$missing, array &$problems): void
    {
        $versions = $document->versions->sortBy('version_number')->values();
        if ($versions->count() < count($filenames)) {
            $missing[] = "Faltan versiones de {$document->name}.";

            return;
        }
        if ($versions->count() > count($filenames)) {
            $problems[] = "Hay versiones adicionales en {$document->name}.";

            return;
        }
        $disk = $this->storeVersion->storageDisk();
        foreach ($filenames as $index => $filename) {
            $version = $versions[$index];
            $expectedNote = $document->name === 'Requisitos de ingreso especial 2027'
                ? self::NOTES[$index + 1]
                : null;
            if ((int) $version->version_number !== $index + 1
                || $version->filename !== $filename
                || ! $version->active
                || $version->is_current !== ($index === array_key_last($filenames))
                || (string) $version->author_id !== self::USERS['editor']['id']
                || $version->comment !== $expectedNote) {
                $problems[] = 'La versión '.($index + 1)." de {$document->name} fue modificada.";

                continue;
            }
            try {
                $contents = Storage::disk($disk)->get($version->url);
            } catch (\Throwable) {
                $contents = null;
            }
            if (! is_string($contents) || hash('sha256', $contents) !== hash_file('sha256', $this->assetPath($filename))) {
                $problems[] = "El archivo {$filename} no está disponible o no coincide con el activo original.";
            }
        }
    }

    /** @return list<string> */
    private function externalCollisions(): array
    {
        $collisions = [];
        if (Institution::query()->where('name', 'Universidad del Río')->whereKeyNot(self::INSTITUTION_ID)->exists()) {
            $collisions[] = 'Ya existe otra institución llamada Universidad del Río.';
        }
        foreach (self::USERS as $definition) {
            $byId = User::withTrashed()->find($definition['id']);
            $byEmail = User::withTrashed()->where('email', $definition['email'])->first();
            if (($byId && ($byId->email !== $definition['email'] || (string) $byId->institution_id !== self::INSTITUTION_ID))
                || ($byEmail && ((string) $byEmail->id !== $definition['id'] || (string) $byEmail->institution_id !== self::INSTITUTION_ID))) {
                $collisions[] = "La identidad {$definition['email']} pertenece a otra institución.";
            }
        }

        return $collisions;
    }

    private function assertAssets(): void
    {
        foreach ($this->allAssetNames() as $filename) {
            $path = $this->assetPath($filename);
            $header = is_file($path) ? file_get_contents($path, false, null, 0, 5) : false;
            if (! is_file($path) || filesize($path) === 0 || ! is_string($header) || ! str_starts_with($header, '%PDF')) {
                throw new RuntimeException("El activo PDF {$filename} no está disponible o no es válido.");
            }
        }
    }

    /** @return list<string> */
    private function allAssetNames(): array
    {
        return [
            ...array_merge(...array_column(self::DOCUMENTS, 'files')),
            'preguntas-frecuentes-admision.pdf',
            'requisitos-ingreso-especial-2027-v3.pdf',
        ];
    }

    private function uploadedAsset(string $filename): UploadedFile
    {
        return new UploadedFile($this->assetPath($filename), $filename, 'application/pdf', null, true);
    }

    private function assetPath(string $filename): string
    {
        return resource_path('demo/universidad-del-rio/'.$filename);
    }

    /** @param list<string> $details @return array{status: string, message: string, details: list<string>} */
    private function result(string $status, string $message, array $details = []): array
    {
        return compact('status', 'message', 'details');
    }
}
