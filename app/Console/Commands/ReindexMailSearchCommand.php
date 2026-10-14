<?php

namespace App\Console\Commands;

use App\Models\MailRecord;
use App\Services\MailSearchIndexer;
use App\Services\SearchCache;
use Illuminate\Console\Command;

class ReindexMailSearchCommand extends Command
{
    protected $signature = 'mail:reindex-search {--chunk=500 : Number of mail records to load at once}';

    protected $description = 'Backfill the searchable mail document without changing mail metadata';

    public function handle(MailSearchIndexer $indexer): int
    {
        $chunk = (int) $this->option('chunk');
        if ($chunk < 1 || $chunk > 1000) {
            $this->error('The chunk size must be between 1 and 1000.');

            return self::FAILURE;
        }

        $total = MailRecord::query()->count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        MailRecord::query()->chunkById($chunk, function ($records) use ($indexer, $bar) {
            $indexer->rebuildMany($records);
            $bar->advance($records->count());
        });
        $bar->finish();
        $this->newLine();
        SearchCache::invalidate();

        return self::SUCCESS;
    }
}
