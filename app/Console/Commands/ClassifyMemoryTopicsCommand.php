<?php

namespace App\Console\Commands;

use App\Domain\Memory\Jobs\ClassifyMemoryTopicJob;
use App\Domain\Memory\Models\Memory;
use Illuminate\Console\Command;

class ClassifyMemoryTopicsCommand extends Command
{
    protected $signature = 'memory:classify-topics
                            {--limit=500 : Max number of memories to process}
                            {--dry-run : Show what would be classified without making changes}';

    protected $description = 'Backfill topic classification for memories that have no topic set';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $query = Memory::withoutGlobalScopes()
            ->whereNull('topic')
            ->whereNotNull('content');

        $count = min($query->count(), $limit);

        if ($count === 0) {
            $this->info('No memories without topics found.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d memories without topics.', $count));

        if ($dryRun) {
            $this->info('[dry-run] Would dispatch '.$count.' ClassifyMemoryTopicJob(s). No changes made.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        $ids = $query->orderBy('created_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            ClassifyMemoryTopicJob::dispatch($id);
            $dispatched++;
        }

        $this->info(sprintf('Dispatched %d classification job(s) to the default queue.', $dispatched));

        return self::SUCCESS;
    }
}
