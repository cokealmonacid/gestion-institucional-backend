<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Documents\Services\BackfillDocumentEvents;

return new class extends Migration
{
    public function up(): void
    {
        // Stable database-level dependency shared with the manual recovery command.
        // It uses query-builder rows, deterministic IDs and explicit source identities;
        // it does not depend on runtime Eloquent models or model events.
        app(BackfillDocumentEvents::class)->execute();
    }

    public function down(): void
    {
        // Historical events are retained. The structural migration owns table removal.
    }
};
