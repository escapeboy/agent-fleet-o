<?php

namespace App\Domain\Budget\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UpstreamCreditLowNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $subProgram,
        public readonly string $provider,
        public readonly int $remaining,
        public readonly int $dailyAvg7d,
        public readonly ?int $daysUntilDepletion,
        public readonly int $budgetCredits,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $creditValueUsd = (float) config('llm_pricing.credit_value_usd', 0.001);
        $remainingUsd = number_format($this->remaining * $creditValueUsd, 2);
        $dailyUsd = number_format($this->dailyAvg7d * $creditValueUsd, 2);
        $days = $this->daysUntilDepletion ?? 0;

        return (new MailMessage)
            ->subject(sprintf(
                '[FleetQ] Upstream credits low: %s / %s (~%d day%s left)',
                $this->subProgram,
                $this->provider,
                $days,
                $days === 1 ? '' : 's',
            ))
            ->greeting('Upstream credit runway warning')
            ->line(sprintf(
                'The platform-funded LLM credits for sub-program "%s" on provider "%s" are running low.',
                $this->subProgram,
                $this->provider,
            ))
            ->line(sprintf('Estimated runway: ~%d day%s until depletion.', $days, $days === 1 ? '' : 's'))
            ->line(sprintf('Remaining: %s credits (≈ $%s).', number_format($this->remaining), $remainingUsd))
            ->line(sprintf('Recent burn (7d avg): %s credits/day (≈ $%s/day).', number_format($this->dailyAvg7d), $dailyUsd))
            ->line(sprintf('Configured allotment: %s credits.', number_format($this->budgetCredits)))
            ->line('Action: top up the provider account, then update the budget entry (credits + since) in config/GlobalSetting so the runway resets.');
    }
}
