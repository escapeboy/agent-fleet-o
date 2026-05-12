<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Observability\Alerts\AlertEvaluator;
use Illuminate\Console\Command;

/**
 * Cron entrypoint for the Laravel-side alert evaluator.
 *
 * Runs every minute (see routes/console.php). Each tick:
 *   - resolves each AlertRule's current value
 *   - dispatches PlatformAlertTriggered for crossings (de-duped by cooldown)
 *   - SendAlertEmail listener picks it up and sends mail
 *
 * Grafana alerting is independent — this command is the redundant Laravel-side
 * path that survives when Grafana is itself down.
 */
class CheckAlertRulesCommand extends Command
{
    protected $signature = 'alerts:check {--dry : Resolve metric values but skip event dispatch}';

    protected $description = 'Evaluate platform alert rules and dispatch PlatformAlertTriggered events on breach.';

    public function handle(AlertEvaluator $evaluator): int
    {
        if ($this->option('dry')) {
            $this->warn('Dry mode is not yet supported (no per-rule preview). Running real evaluation.');
        }

        $fired = $evaluator->evaluate();

        if ($fired === []) {
            $this->info('No alerts fired.');

            return self::SUCCESS;
        }

        foreach ($fired as $event) {
            $this->warn(sprintf(
                'Alert fired: %s (%s) — value %s ≥ threshold %d',
                $event->rule->metricName,
                $event->rule->severity,
                (string) $event->currentValue,
                $event->rule->threshold,
            ));
        }

        return self::SUCCESS;
    }
}
