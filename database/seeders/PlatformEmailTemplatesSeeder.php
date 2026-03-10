<?php

namespace Database\Seeders;

use App\Domain\Email\Enums\EmailTemplateStatus;
use App\Domain\Email\Enums\EmailTemplateVisibility;
use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Email\Models\EmailTheme;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlatformEmailTemplatesSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $team = Team::withoutGlobalScopes()->where('slug', 'fleetq-platform')->first();

        if (! $team) {
            $this->command?->warn('Platform team not found. Run PlatformTeamSeeder first.');

            return;
        }

        $cleanTheme = EmailTheme::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('name', 'Clean & Professional')
            ->first();

        $count = 0;

        foreach ($this->definitions($cleanTheme?->id) as $def) {
            EmailTemplate::withoutGlobalScopes()->updateOrCreate(
                ['team_id' => $team->id, 'name' => $def['name']],
                array_merge($def, [
                    'team_id' => $team->id,
                    'status' => EmailTemplateStatus::Active,
                    'visibility' => EmailTemplateVisibility::Public,
                ]),
            );

            $count++;
        }

        $this->command?->info("Platform email templates seeded: {$count}");
    }

    private function definitions(?string $themeId): array
    {
        $block = fn (string $type, array $data) => array_merge(['type' => $type], $data);
        $heading = fn (string $text) => $block('heading', ['text' => $text, 'level' => 1]);
        $text = fn (string $content) => $block('paragraph', ['text' => $content]);
        $button = fn (string $label, string $url = '{{action_url}}') => $block('button', ['label' => $label, 'url' => $url, 'align' => 'center']);
        $divider = fn () => $block('divider', []);

        return [
            [
                'name' => 'Welcome Email',
                'email_theme_id' => $themeId,
                'subject' => 'Welcome to {{company_name}}, {{user_name}}!',
                'preview_text' => 'Your account is ready. Here\'s how to get started.',
                'design_json' => [
                    'blocks' => [
                        $heading('Welcome, {{user_name}}! 👋'),
                        $text('We\'re thrilled to have you on board. Your account is set up and ready to go.'),
                        $text('Here\'s what you can do first:'),
                        $block('list', ['items' => ['Complete your profile setup', 'Explore the dashboard', 'Create your first project', 'Invite your team']]),
                        $button('Get Started'),
                        $divider(),
                        $text('If you have any questions, reply to this email — we\'re here to help.'),
                    ],
                ],
            ],
            [
                'name' => 'Email Verification',
                'email_theme_id' => $themeId,
                'subject' => 'Verify your email address',
                'preview_text' => 'Click to confirm your email — this link expires in 24 hours.',
                'design_json' => [
                    'blocks' => [
                        $heading('Verify your email address'),
                        $text('Thanks for signing up! Please confirm your email address to activate your account.'),
                        $button('Verify Email Address'),
                        $text('This link expires in 24 hours. If you didn\'t sign up, you can ignore this email.'),
                        $divider(),
                        $text('Or copy this URL into your browser: {{verification_url}}'),
                    ],
                ],
            ],
            [
                'name' => 'Password Reset',
                'email_theme_id' => $themeId,
                'subject' => 'Reset your password',
                'preview_text' => 'Someone requested a password reset for your account.',
                'design_json' => [
                    'blocks' => [
                        $heading('Reset your password'),
                        $text('We received a request to reset the password for your account. Click the button below to choose a new password.'),
                        $button('Reset Password'),
                        $text('This link expires in 1 hour. If you didn\'t request a password reset, you can safely ignore this email — your password won\'t change.'),
                    ],
                ],
            ],
            [
                'name' => 'Trial Ending Reminder',
                'email_theme_id' => $themeId,
                'subject' => 'Your free trial ends in {{days_remaining}} days',
                'preview_text' => 'Upgrade now to keep access to all your work.',
                'design_json' => [
                    'blocks' => [
                        $heading('Your trial ends soon'),
                        $text('Hi {{user_name}}, your free trial of {{product_name}} ends on {{trial_end_date}}.'),
                        $text('You\'ve made great progress — don\'t lose access to your {{item_count}} projects and {{storage_used}} of data.'),
                        $button('Upgrade Now'),
                        $divider(),
                        $text('Questions about pricing? Reply to this email and we\'ll help you find the right plan.'),
                    ],
                ],
            ],
            [
                'name' => 'Invoice Ready',
                'email_theme_id' => $themeId,
                'subject' => 'Your invoice for {{invoice_period}} is ready',
                'preview_text' => 'Invoice #{{invoice_number}} · {{invoice_amount}}',
                'design_json' => [
                    'blocks' => [
                        $heading('Your invoice is ready'),
                        $block('table', [
                            'rows' => [
                                ['label' => 'Invoice number', 'value' => '{{invoice_number}}'],
                                ['label' => 'Period', 'value' => '{{invoice_period}}'],
                                ['label' => 'Amount due', 'value' => '{{invoice_amount}}'],
                                ['label' => 'Due date', 'value' => '{{invoice_due_date}}'],
                            ],
                        ]),
                        $button('View Invoice'),
                        $text('Thank you for your business. If you have any questions about this invoice, please contact our billing team.'),
                    ],
                ],
            ],
            [
                'name' => 'Payment Failed',
                'email_theme_id' => $themeId,
                'subject' => 'Action required: payment failed for your subscription',
                'preview_text' => 'Update your payment method to avoid service interruption.',
                'design_json' => [
                    'blocks' => [
                        $heading('Payment failed'),
                        $text('Hi {{user_name}}, we weren\'t able to process your payment of {{amount}} for your {{plan_name}} subscription on {{attempt_date}}.'),
                        $text('To avoid any interruption to your service, please update your payment details.'),
                        $button('Update Payment Method'),
                        $text('We\'ll retry the payment in {{retry_days}} days. If payment continues to fail, your account will be downgraded to the free plan.'),
                    ],
                ],
            ],
            [
                'name' => 'Weekly Activity Digest',
                'email_theme_id' => $themeId,
                'subject' => 'Your week in review: {{week_of}}',
                'preview_text' => '{{experiments_run}} experiments · {{tasks_completed}} tasks · {{cost_used}} credits used',
                'design_json' => [
                    'blocks' => [
                        $heading('Your weekly summary'),
                        $text('Here\'s what happened in your workspace this week ({{week_start}} – {{week_end}}):'),
                        $block('stats', [
                            'items' => [
                                ['label' => 'Experiments run', 'value' => '{{experiments_run}}'],
                                ['label' => 'Tasks completed', 'value' => '{{tasks_completed}}'],
                                ['label' => 'Success rate', 'value' => '{{success_rate}}%'],
                                ['label' => 'Credits used', 'value' => '{{credits_used}}'],
                            ],
                        ]),
                        $button('View Dashboard'),
                    ],
                ],
            ],
            [
                'name' => 'Alert Triggered Notification',
                'email_theme_id' => $themeId,
                'subject' => '⚠️ Alert: {{alert_name}}',
                'preview_text' => '{{alert_summary}} — triggered at {{triggered_at}}',
                'design_json' => [
                    'blocks' => [
                        $heading('Alert triggered'),
                        $block('callout', ['style' => 'warning', 'text' => '{{alert_name}}']),
                        $text('{{alert_description}}'),
                        $block('table', [
                            'rows' => [
                                ['label' => 'Triggered at', 'value' => '{{triggered_at}}'],
                                ['label' => 'Severity', 'value' => '{{alert_severity}}'],
                                ['label' => 'Affected component', 'value' => '{{affected_component}}'],
                            ],
                        ]),
                        $button('View Details'),
                        $text('This alert was configured in your workspace notification settings.'),
                    ],
                ],
            ],
            [
                'name' => 'Approval Required',
                'email_theme_id' => $themeId,
                'subject' => 'Approval required: {{approval_title}}',
                'preview_text' => 'Your input is needed to continue the workflow.',
                'design_json' => [
                    'blocks' => [
                        $heading('Your approval is needed'),
                        $text('Hi {{reviewer_name}}, the following item requires your review and approval:'),
                        $block('callout', ['style' => 'info', 'text' => '{{approval_title}}']),
                        $text('{{approval_description}}'),
                        $button('Review & Approve'),
                        $text('This request expires on {{expires_at}}. If not actioned, it will be escalated to {{escalation_contact}}.'),
                    ],
                ],
            ],
            [
                'name' => 'Onboarding Day 1',
                'email_theme_id' => $themeId,
                'subject' => 'Day 1: Let\'s get you set up, {{user_name}}',
                'preview_text' => 'Your first task: connect a data source and run your first experiment.',
                'design_json' => [
                    'blocks' => [
                        $heading('Welcome to Day 1'),
                        $text('Hi {{user_name}}, let\'s make your first day count. Most teams see results within 48 hours by completing these three things:'),
                        $block('numbered_list', ['items' => [
                            'Connect your first data source or signal',
                            'Run your first experiment or workflow',
                            'Invite at least one teammate',
                        ]]),
                        $button('Start Setup →'),
                        $divider(),
                        $text('Need help? Check our quick-start guide or reply to this email.'),
                    ],
                ],
            ],
            [
                'name' => 'Re-engagement Email',
                'email_theme_id' => $themeId,
                'subject' => 'We miss you, {{user_name}}',
                'preview_text' => 'It\'s been {{days_inactive}} days. Here\'s what you\'ve missed.',
                'design_json' => [
                    'blocks' => [
                        $heading('Long time no see'),
                        $text('Hi {{user_name}}, it\'s been {{days_inactive}} days since you last logged in. A lot has happened since then:'),
                        $block('list', ['items' => [
                            '{{feature_1}}',
                            '{{feature_2}}',
                            '{{feature_3}}',
                        ]]),
                        $button('Come Back'),
                        $divider(),
                        $text('If you\'ve moved on, no hard feelings. You can unsubscribe below.'),
                    ],
                ],
            ],
            [
                'name' => 'Monthly Usage Report',
                'email_theme_id' => $themeId,
                'subject' => 'Your {{month}} usage report',
                'preview_text' => 'See how your team used {{product_name}} this month.',
                'design_json' => [
                    'blocks' => [
                        $heading('{{month}} Usage Report'),
                        $text('Here\'s a summary of your workspace activity in {{month}}:'),
                        $block('stats', [
                            'items' => [
                                ['label' => 'Total experiments', 'value' => '{{total_experiments}}'],
                                ['label' => 'Successful runs', 'value' => '{{successful_runs}}'],
                                ['label' => 'Active agents', 'value' => '{{active_agents}}'],
                                ['label' => 'Credits consumed', 'value' => '{{credits_consumed}}'],
                                ['label' => 'Credits remaining', 'value' => '{{credits_remaining}}'],
                            ],
                        ]),
                        $button('View Full Report'),
                        $divider(),
                        $text('Your plan resets on {{reset_date}}. Questions about usage or billing? Reply to this email.'),
                    ],
                ],
            ],
        ];
    }
}
