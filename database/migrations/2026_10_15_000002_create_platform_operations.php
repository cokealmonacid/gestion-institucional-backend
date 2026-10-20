<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('operator_id')->constrained('platform_operators')->restrictOnDelete();
            $t->string('operation_type', 64);
            $t->uuid('idempotency_key');
            $t->char('fingerprint', 64);
            $t->json('result')->nullable();
            $t->timestamp('committed_at', 6)->nullable();
            $t->timestamps();
            $t->unique(['operator_id', 'operation_type', 'idempotency_key'], 'platform_operations_scope_unique');
        });
    }

    public function down(): void
    {
        throw new LogicException('Committed operation history must be retained. Use forward repair.');
    }
};
