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
        Schema::create('nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('path');
            $table->unsignedSmallInteger('depth')->default(0);
            $table->string('order');
            $table->boolean('active')->default(true);
            $table->foreignUuid('intitution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignUuid('parent_id')
            ->nullable()
            ->constrained('nodes')
            ->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
