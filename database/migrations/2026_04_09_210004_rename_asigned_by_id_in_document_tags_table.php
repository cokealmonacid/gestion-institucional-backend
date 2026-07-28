<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_tags', function (Blueprint $table) {
            $table->renameColumn('asigned_by_id', 'assigned_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('document_tags', function (Blueprint $table) {
            $table->renameColumn('assigned_by_id', 'asigned_by_id');
        });
    }
};
