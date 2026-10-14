<?php

namespace App\Jobs;

use App\Services\MailSearchIndexer;
use App\Services\SearchCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReindexMailSearch implements ShouldQueue
{
    use Queueable;

    /** @param list<int> $mailIds */
    public function __construct(public array $mailIds) {}

    public function handle(MailSearchIndexer $indexer): void
    {
        $indexer->rebuildMany($this->mailIds);
        SearchCache::invalidate();
    }
}
