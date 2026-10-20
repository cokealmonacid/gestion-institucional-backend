<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_access_recoveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('operator_id');
            $table->uuid('institution_id')->index();
            $table->uuid('target_id')->index();
            $table->string('kind', 32);
            $table->string('state', 32);
            $table->unsignedInteger('revision')->default(1);
            $table->string('fingerprint', 64);
            $table->json('request');
            $table->json('snapshot');
            $table->string('reserved_email')->nullable()->unique();
            $table->string('verification_hash')->nullable();
            $table->string('prepared_password')->nullable();
            $table->unsignedInteger('generation')->default(1);
            $table->string('publication_status', 32)->default('pending');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_access_recoveries');
    }
};
