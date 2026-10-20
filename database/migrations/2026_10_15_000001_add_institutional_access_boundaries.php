<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', fn (Blueprint $t) => $t->unsignedBigInteger('auth_epoch')->default(0));
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedBigInteger('access_revision')->default(0);
            $t->index(['institution_id', 'active', 'deleted_at'], 'users_institution_usage_index');
        });
        Schema::table('user_invitations', function (Blueprint $t) {
            $t->timestamp('revoked_at')->nullable();
            $t->unsignedBigInteger('institution_auth_epoch')->default(0);
        });
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->unsignedBigInteger('institution_auth_epoch')->nullable());
    }

    public function down(): void
    {
        throw new LogicException('Credential revocation cannot safely be reversed. Use a forward repair.');
    }
};
