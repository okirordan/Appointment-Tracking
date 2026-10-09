<?php

namespace App\Console\Commands;

use App\Models\TaskHistory;
use App\Services\Tasks\TaskAnnotationCorrespondenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTaskAnnotationCorrespondence extends Command
{
    protected $signature = 'mail:backfill-task-annotations';

    protected $description = 'Record existing mail-linked task annotations as correspondence entries';

    public function handle(TaskAnnotationCorrespondenceService $correspondence): int
    {
        $created = 0;
        TaskHistory::query()->where('action_type', 'Annotated')->orderBy('id')->chunkById(100, function ($histories) use ($correspondence, &$created) {
            foreach ($histories as $history) {
                $created += DB::transaction(fn () => $correspondence->record($history));
            }
        });

        $this->components->info("Recorded {$created} mail-linked task annotations as correspondence.");

        return self::SUCCESS;
    }
}
