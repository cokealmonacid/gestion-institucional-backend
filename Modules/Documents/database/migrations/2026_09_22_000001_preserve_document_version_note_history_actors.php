<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HISTORY_TIMELINE_INDEX = 'doc_ver_note_hist_timeline_idx';

    public function up(): void
    {
        Schema::table('document_version_comment_histories', function (Blueprint $table) {
            $table->string('actor_name')->nullable()->after('user_id');
            $table->index(
                ['document_id', 'document_version_id', 'created_at', 'id'],
                self::HISTORY_TIMELINE_INDEX,
            );
        });

        DB::table('document_version_comment_histories')
            ->whereNull('actor_name')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->eachById(function (object $history): void {
                $name = DB::table('users')->where('id', $history->user_id)->value('name');

                if (is_string($name)) {
                    DB::table('document_version_comment_histories')
                        ->where('id', $history->id)
                        ->update(['actor_name' => $name]);
                }
            }, 100, 'id');
    }

    public function down(): void
    {
        Schema::table('document_version_comment_histories', function (Blueprint $table) {
            $table->dropIndex(self::HISTORY_TIMELINE_INDEX);
            $table->dropColumn('actor_name');
        });
    }
};
