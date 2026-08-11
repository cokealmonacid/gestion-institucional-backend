<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->text('path_ids')->nullable();
            $table->unsignedInteger('position')->nullable();
            $table->string('parent_scope', 38)->nullable();
            $table->text('normalized_name')->nullable();
            $table->char('name_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn([
                'path_ids',
                'position',
                'parent_scope',
                'normalized_name',
                'name_fingerprint',
            ]);
        });
    }
};
