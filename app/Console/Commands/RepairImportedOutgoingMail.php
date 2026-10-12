<?php

namespace App\Console\Commands;

use App\Services\Imports\ImportedOutgoingMailRepairService;
use Illuminate\Console\Command;

class RepairImportedOutgoingMail extends Command
{
    protected $signature = 'mail:repair-imported-outgoing';

    protected $description = 'Repair JSON details, PS office routing, and correspondence for imported outgoing mail';

    public function handle(ImportedOutgoingMailRepairService $repair): int
    {
        $result = $repair->repair();
        $this->info("Repaired {$result['repaired']} outgoing mails; created {$result['notes_created']} correspondence notes and updated {$result['notes_updated']}.");

        return self::SUCCESS;
    }
}
