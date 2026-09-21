<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('type', 80);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->uuid('version_id')->nullable();
            $table->json('detail');
            $table->string('origin', 20);
            $table->string('source_type', 80);
            $table->string('source_id', 191);
            $table->timestamp('occurred_at', 6);
            $table->timestamps(6);

            $table->unique(['source_type', 'source_id'], 'document_events_source_unique');
            $table->index(['document_id', 'occurred_at', 'id'], 'document_events_timeline_index');
            $table->index(['institution_id', 'document_id'], 'document_events_tenant_document_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_events');
    }
};
