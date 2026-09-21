<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Documents\Services\BackfillDocumentEvents as BackfillService;

class BackfillDocumentEvents extends Command
{
    protected $signature = 'documents:backfill-events';

    protected $description = 'Idempotently backfill proven historical document events';

    public function handle(BackfillService $backfill): int
    {
        $counts = $backfill->execute();
        $this->info(sprintf(
            'Inserted %d document, %d version, and %d responsibility event(s).',
            $counts['documents'],
            $counts['versions'],
            $counts['responsibilities'],
        ));

        return self::SUCCESS;
    }
}
