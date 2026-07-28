<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->float('verion_number');
            $table->string('url');
            $table->string('filename');
            $table->string('mime_type');
            $table->float('file_size');
            $table->text('comment')->nullable();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('node_id')->nullable()->constrained('nodes')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
