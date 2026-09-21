<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DOCUMENT_RESPONSIBLE_INDEX = 'docs_resp_user_idx';

    private const DOCUMENT_RESPONSIBLE_FOREIGN = 'docs_resp_user_fk';

    private const HISTORY_DOCUMENT_INDEX = 'doc_resp_hist_doc_idx';

    private const HISTORY_DOCUMENT_FOREIGN = 'doc_resp_hist_doc_fk';

    private const HISTORY_PREVIOUS_USER_INDEX = 'doc_resp_hist_prev_user_idx';

    private const HISTORY_PREVIOUS_USER_FOREIGN = 'doc_resp_hist_prev_user_fk';

    private const HISTORY_NEW_USER_INDEX = 'doc_resp_hist_new_user_idx';

    private const HISTORY_NEW_USER_FOREIGN = 'doc_resp_hist_new_user_fk';

    private const HISTORY_ACTOR_INDEX = 'doc_resp_hist_actor_idx';

    private const HISTORY_ACTOR_FOREIGN = 'doc_resp_hist_actor_fk';

    private const HISTORY_DOCUMENT_REVISION_UNIQUE = 'doc_resp_hist_doc_rev_uq';

    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('responsible_user_id')->nullable()->after('responsible_unit');
            $table->unsignedInteger('responsibility_revision')->default(0)->after('responsible_user_id');
            $table->index('responsible_user_id', self::DOCUMENT_RESPONSIBLE_INDEX);
            $table->foreign('responsible_user_id', self::DOCUMENT_RESPONSIBLE_FOREIGN)
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('document_responsible_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            $table->uuid('previous_responsible_user_id')->nullable();
            $table->uuid('new_responsible_user_id')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->string('previous_responsible_name')->nullable();
            $table->string('new_responsible_name')->nullable();
            $table->unsignedInteger('revision');
            $table->timestamps();

            $table->index('document_id', self::HISTORY_DOCUMENT_INDEX);
            $table->index('previous_responsible_user_id', self::HISTORY_PREVIOUS_USER_INDEX);
            $table->index('new_responsible_user_id', self::HISTORY_NEW_USER_INDEX);
            $table->index('actor_user_id', self::HISTORY_ACTOR_INDEX);
            $table->unique(['document_id', 'revision'], self::HISTORY_DOCUMENT_REVISION_UNIQUE);
            $table->foreign('document_id', self::HISTORY_DOCUMENT_FOREIGN)
                ->references('id')->on('documents')->cascadeOnDelete();
            $table->foreign('previous_responsible_user_id', self::HISTORY_PREVIOUS_USER_FOREIGN)
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('new_responsible_user_id', self::HISTORY_NEW_USER_FOREIGN)
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('actor_user_id', self::HISTORY_ACTOR_FOREIGN)
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_responsible_histories');

        Schema::table('documents', function (Blueprint $table) {
            if (DB::getDriverName() === 'sqlite') {
                $table->dropForeign(['responsible_user_id']);
            } else {
                $table->dropForeign(self::DOCUMENT_RESPONSIBLE_FOREIGN);
            }
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(self::DOCUMENT_RESPONSIBLE_INDEX);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['responsible_user_id', 'responsibility_revision']);
        });
    }
};
