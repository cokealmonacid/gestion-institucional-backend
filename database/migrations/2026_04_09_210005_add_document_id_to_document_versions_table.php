<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropColumn('document_id');
        });
    }
};
