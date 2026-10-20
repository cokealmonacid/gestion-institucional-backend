<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['document_responsible_histories', 'document_events'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->uuid('actor_platform_operator_id')->nullable();
                $table->string('actor_platform_operator_name')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['document_responsible_histories', 'document_events'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn(['actor_platform_operator_id', 'actor_platform_operator_name']));
        }
    }
};
