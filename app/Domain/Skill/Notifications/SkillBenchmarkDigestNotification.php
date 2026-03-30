<?php

namespace App\Domain\Skill\Notifications;

use App\Domain\Skill\Enums\IterationOutcome;
use App\Domain\Skill\Models\SkillBenchmark;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SkillBenchmarkDigestNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly SkillBenchmark $benchmark,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $benchmark = $this->benchmark;
        $skill = $benchmark->skill;
        $logs = $benchmark->iterationLogs;

        $keepCount = $logs->where('outcome', IterationOutcome::Keep)->count();
        $discardCount = $logs->where('outcome', IterationOutcome::Discard)->count();
        $crashCount = $logs->where('outcome', IterationOutcome::Crash)->count();
        $totalIterations = $logs->count();
        $improvementPercent = $benchmark->improvementPercent();

        $topKeeps = $logs
            ->where('outcome', IterationOutcome::Keep)
            ->sortByDesc('metric_value')
            ->take(3);

        $message = (new MailMessage)
            ->subject("Benchmark complete: {$skill->name}")
            ->greeting('Benchmark Complete!')
            ->line("Your skill **{$skill->name}** has finished its autonomous improvement loop.")
            ->line("**Metric:** {$benchmark->metric_name} ({$benchmark->metric_direction})")
            ->line('**Baseline:** '.number_format((float) $benchmark->baseline_value, 4))
            ->line('**Best achieved:** '.number_format((float) $benchmark->best_value, 4).' ('.($improvementPercent >= 0 ? '+' : '').$improvementPercent.'%)')
            ->line("**Iterations:** {$totalIterations} total — {$keepCount} kept, {$discardCount} discarded, {$crashCount} crashed");

        if ($topKeeps->isNotEmpty()) {
            $message->line('**Top improvements:**');
            foreach ($topKeeps as $log) {
                $message->line(sprintf(
                    '- Iteration %d: metric=%.4f (Δ%.4f)',
                    $log->iteration_number,
                    $log->metric_value ?? 0,
                    ($log->metric_value ?? 0) - $log->baseline_at_iteration,
                ));
            }
        }

        return $message
            ->action('View Skill', url('/skills/'.$skill->id))
            ->line('The improved version is now active for this skill.');
    }
}
