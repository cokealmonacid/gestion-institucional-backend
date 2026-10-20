<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_operators', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedBigInteger('auth_epoch')->default(0);
            $t->text('totp_secret')->nullable();
            $t->text('pending_totp_secret')->nullable();
            $t->timestamp('enrollment_expires_at')->nullable();
            $t->timestamp('mfa_confirmed_at')->nullable();
            $t->bigInteger('last_totp_step')->default(-1);
            $t->timestamps();
        });
        Schema::create('platform_auth_challenges', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('operator_id')->constrained('platform_operators')->restrictOnDelete();
            $t->string('token_hash');
            $t->string('purpose', 32);
            $t->unsignedBigInteger('auth_epoch');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('platform_recovery_codes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('operator_id')->constrained('platform_operators')->restrictOnDelete();
            $t->string('code_hash');
            $t->timestamp('consumed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('platform_access_links', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('operator_id')->constrained('platform_operators')->restrictOnDelete();
            $t->string('purpose', 32);
            $t->string('token_hash')->nullable();
            $t->unsignedBigInteger('generation')->default(1);
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->string('publication_status')->default('pending');
            $t->timestamps();
            $t->unique(['operator_id', 'purpose']);
        });
        Schema::create('platform_cli_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('fingerprint');
            $t->uuid('operator_id');
            $t->timestamps();
        });
        Schema::create('platform_bootstrap_lock', function (Blueprint $t) {
            $t->integer('id')->primary();
        });
        DB::table('platform_bootstrap_lock')->insert(['id' => 1]);
        Schema::create('administrative_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('actor_kind');
            $t->string('actor_id');
            $t->string('actor_name');
            $t->string('action');
            $t->string('subject_kind');
            $t->uuid('subject_id');
            $t->uuid('institution_id')->nullable()->index();
            $t->json('before_values');
            $t->json('after_values');
            $t->string('reason', 1000);
            $t->uuid('operation_id');
            $t->timestamp('occurred_at', 6);
            $t->index(['occurred_at', 'id']);
        });
        Schema::create('catalog_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code')->unique();
            $t->string('name');
            $t->text('description');
            $t->boolean('available_for_new_contracts');
            $t->json('limits');
            $t->unsignedBigInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::table('institutions', function (Blueprint $t) {
            $t->unsignedBigInteger('operational_revision')->default(0);
            $t->string('commercial_state')->default('legacy_unconfigured');
            $t->string('customer_type')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_rut', 12)->nullable();
        });
        Schema::create('institution_contracts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('institution_id')->unique()->constrained('institutions')->restrictOnDelete();
            $t->uuid('current_revision_id')->nullable();
            $t->unsignedBigInteger('revision')->default(0);
            $t->timestamps();
        });
        Schema::create('institution_contract_revisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('contract_id')->constrained('institution_contracts')->restrictOnDelete();
            $t->foreignUuid('institution_id')->constrained('institutions')->restrictOnDelete();
            $t->unsignedBigInteger('revision');
            $t->timestamp('effective_at', 6);
            $t->json('conditions');
            $t->uuid('actor_id');
            $t->uuid('operation_id');
            $t->unique(['contract_id', 'revision']);
        });
        Schema::table('personal_access_tokens', function (Blueprint $t) {
            $t->unsignedBigInteger('platform_auth_epoch')->nullable();
            $t->timestamp('platform_mfa_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        // Reverting after commercial/authentication writes requires forward repair.
        if (DB::table('administrative_events')->exists()) {
            throw new LogicException('Platform history exists; use forward repair.');
        }
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->dropColumn(['platform_auth_epoch', 'platform_mfa_verified_at']));
        Schema::dropIfExists('institution_contract_revisions');
        Schema::dropIfExists('institution_contracts');
        Schema::table('institutions', fn (Blueprint $t) => $t->dropColumn(['operational_revision', 'commercial_state', 'customer_type', 'customer_name', 'customer_rut']));
        foreach (['catalog_plans', 'administrative_events', 'platform_bootstrap_lock', 'platform_cli_operations', 'platform_access_links', 'platform_recovery_codes', 'platform_auth_challenges', 'platform_operators'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
