<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DOCUMENT_ID_INDEX = 'document_versions_document_id_index';

    public function up(): void
    {
        DB::table('document_versions')
            ->select('document_id')
            ->distinct()
            ->orderBy('document_id')
            ->each(function ($document): void {
                $versions = DB::table('document_versions')
                    ->where('document_id', $document->document_id)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get();

                $validGroups = $versions
                    ->filter(fn ($version) => $this->isPositiveInteger($version->version_number))
                    ->groupBy(fn ($version) => (string) (int) $version->version_number);
                $preservedIds = $validGroups
                    ->map(fn ($group) => $group->first()->id)
                    ->values()
                    ->all();
                $nextNumber = $validGroups->keys()
                    ->map(fn ($number) => (int) $number)
                    ->max() ?? 0;

                foreach ($versions->whereNotIn('id', $preservedIds) as $version) {
                    DB::table('document_versions')->where('id', $version->id)->update([
                        'version_number' => ++$nextNumber,
                    ]);
                }

                $currentId = $versions
                    ->filter(fn ($version) => (bool) $version->active && (bool) $version->is_current)
                    ->sort(function ($left, $right): int {
                        return [(float) $right->version_number, $right->created_at, $right->id]
                            <=> [(float) $left->version_number, $left->created_at, $left->id];
                    })
                    ->first()?->id;

                DB::table('document_versions')
                    ->where('document_id', $document->document_id)
                    ->update(['is_current' => false]);

                if ($currentId !== null) {
                    DB::table('document_versions')->where('id', $currentId)->update(['is_current' => true]);
                }
            });

        $this->ensureDocumentIdIndex();

        Schema::table('document_versions', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_marker')
                ->nullable()
                ->virtualAs('CASE WHEN is_current = 1 THEN 1 ELSE NULL END')
                ->after('is_current');
            $table->unique(['document_id', 'version_number'], 'document_versions_number_unique');
            $table->unique(['document_id', 'current_marker'], 'document_versions_current_unique');
        });

        $this->ensureDocumentIdIndex();

        $this->addActiveCurrentConstraint();
    }

    public function down(): void
    {
        $this->ensureDocumentIdIndex();

        $this->dropActiveCurrentConstraint();

        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropUnique('document_versions_number_unique');
            $table->dropUnique('document_versions_current_unique');
            $table->dropColumn('current_marker');
        });

        // Repaired legacy numbers cannot be reconstructed by this rollback.
    }

    private function ensureDocumentIdIndex(): void
    {
        $indexes = collect(Schema::getIndexes('document_versions'));

        if ($indexes->contains(fn (array $index) => $index['name'] === self::DOCUMENT_ID_INDEX)) {
            return;
        }

        $simpleIndex = $indexes->first(fn (array $index) => $index['columns'] === ['document_id']);

        if ($simpleIndex !== null && DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE `document_versions` RENAME INDEX `%s` TO `%s`',
                str_replace('`', '``', $simpleIndex['name']),
                self::DOCUMENT_ID_INDEX,
            ));

            return;
        }

        if ($simpleIndex === null) {
            Schema::table('document_versions', function (Blueprint $table) {
                $table->index('document_id', self::DOCUMENT_ID_INDEX);
            });
        }
    }

    private function isPositiveInteger(mixed $number): bool
    {
        return is_numeric($number) && (float) $number > 0 && floor((float) $number) === (float) $number;
    }

    private function addActiveCurrentConstraint(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER document_versions_active_current_insert
                BEFORE INSERT ON document_versions
                WHEN NEW.is_current = 1 AND NEW.active <> 1
                BEGIN
                    SELECT RAISE(ABORT, 'A current document version must be active.');
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER document_versions_active_current_update
                BEFORE UPDATE OF active, is_current ON document_versions
                WHEN NEW.is_current = 1 AND NEW.active <> 1
                BEGIN
                    SELECT RAISE(ABORT, 'A current document version must be active.');
                END
                SQL);

            return;
        }

        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT document_versions_active_current_check CHECK (is_current = 0 OR active = 1)');
    }

    private function dropActiveCurrentConstraint(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS document_versions_active_current_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS document_versions_active_current_update');

            return;
        }

        DB::statement('ALTER TABLE document_versions DROP CHECK document_versions_active_current_check');
    }
};
