<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Resets the PHP OPcache so freshly-deployed PHP files take effect.
 *
 * Laravel core does NOT ship an `opcache:clear` command — `optimize:clear`
 * only flushes framework caches (config, route, view, event), not PHP-FPM's
 * compiled bytecode. After a deploy, FPM workers keep executing the previous
 * build's bytecode until natural eviction kicks in.
 *
 * Wire this into `scripts/deploy.sh` AFTER rebuilding framework caches so
 * the new compiled files become immediately visible.
 */
class OpcacheClearCommand extends Command
{
    protected $signature = 'opcache:clear';

    protected $description = 'Reset PHP OPcache (compiled bytecode + JIT) so newly-deployed files are picked up.';

    public function handle(): int
    {
        if (! function_exists('opcache_reset')) {
            $this->warn('OPcache extension not loaded — nothing to reset.');

            return self::SUCCESS;
        }

        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

        if ($status === false) {
            $this->warn('OPcache disabled or restricted (opcache.restrict_api may be set) — nothing to reset.');

            return self::SUCCESS;
        }

        $cachedScripts = (int) ($status['opcache_statistics']['num_cached_scripts'] ?? 0);

        if (! @opcache_reset()) {
            $this->error('opcache_reset() returned false — likely opcache.restrict_api is restricting this PID.');

            return self::FAILURE;
        }

        $this->info("OPcache reset. Evicted {$cachedScripts} cached script(s).");

        return self::SUCCESS;
    }
}
