<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Copy existing files from a local disk to an S3 upload disk, preserving keys.
 *
 * Keys are copied verbatim, so objects already laid out as tenants/{team}/...
 * (e.g. medialibrary media) land in the right place. Legacy differently-keyed
 * assets (old chatbot-knowledge/, websites/) need their DB rows rewritten
 * separately and are out of scope for this generic copy.
 */
class MigrateStorageToS3Command extends Command
{
    protected $signature = 'storage:migrate-to-s3
        {--from=local : Source disk}
        {--to=s3_private : Target disk}
        {--prefix= : Only migrate keys under this prefix (e.g. tenants)}
        {--overwrite : Re-copy keys that already exist on the target}
        {--dry-run : List what would be copied without writing}';

    protected $description = 'Copy files from a local disk to an S3 upload disk, preserving keys.';

    public function handle(): int
    {
        $from = Storage::disk($this->option('from'));
        $to = Storage::disk($this->option('to'));
        $prefix = $this->option('prefix') ?: null;
        $dryRun = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');

        $files = $prefix ? $from->allFiles($prefix) : $from->allFiles();

        $copied = 0;
        $skipped = 0;
        $bytes = 0;

        foreach ($files as $key) {
            if (! $overwrite && $to->exists($key)) {
                $skipped++;

                continue;
            }

            $bytes += $from->size($key);

            if ($dryRun) {
                $this->line("would copy: {$key}");
                $copied++;

                continue;
            }

            $stream = $from->readStream($key);
            if (! is_resource($stream)) {
                $this->warn("unreadable, skipped: {$key}");
                $skipped++;

                continue;
            }

            $to->writeStream($key, $stream);
            fclose($stream);

            $copied++;
        }

        $this->info(sprintf(
            '%s %d file(s) (%s), skipped %d existing.',
            $dryRun ? 'Would copy' : 'Copied',
            $copied,
            $this->humanBytes($bytes),
            $skipped,
        ));

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1_073_741_824) {
            return round($bytes / 1_073_741_824, 2).' GB';
        }
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 2).' MB';
        }

        return round($bytes / 1024, 2).' KB';
    }
}
