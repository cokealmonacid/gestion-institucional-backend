<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->foreignUuid('owner_user_id')
                ->nullable()
                ->unique()
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('provisioning_status')->default('ready');
            $table->timestamp('onboarding_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropForeign(['owner_user_id']);
            $table->dropUnique(['owner_user_id']);
            $table->dropColumn(['owner_user_id', 'provisioning_status', 'onboarding_completed_at']);
        });
    }
};
