<?php

namespace App\Domain\Credential\Services;

/**
 * Regex-based library for detecting accidentally embedded secrets in text fields.
 *
 * Each pattern is keyed by a unique pattern ID. The 'regex' must match the full
 * secret token (use word-boundary or context anchors where possible to reduce false
 * positives). The 'name' is a human-readable label for audit entries and UI display.
 *
 * Patterns are intentionally conservative — they require enough structural entropy
 * to avoid flagging innocuous strings (e.g. random UUIDs or short alphanumeric IDs).
 */
class SecretPatternLibrary
{
    /**
     * @return array<string, array{name: string, regex: string}>
     */
    public function patterns(): array
    {
        return [
            'OPENAI_KEY' => [
                'name' => 'OpenAI API key',
                'regex' => '/sk-[A-Za-z0-9_\-]{40,}/',
            ],
            'ANTHROPIC_KEY' => [
                'name' => 'Anthropic API key',
                'regex' => '/sk-ant-[A-Za-z0-9\-]{90,}/',
            ],
            'GITHUB_PAT' => [
                'name' => 'GitHub Personal Access Token',
                'regex' => '/ghp_[A-Za-z0-9]{36,}/',
            ],
            'GITHUB_OAUTH' => [
                'name' => 'GitHub OAuth token',
                'regex' => '/gho_[A-Za-z0-9]{36}/',
            ],
            'GOOGLE_API' => [
                'name' => 'Google API key',
                'regex' => '/AIza[A-Za-z0-9\-_]{35}/',
            ],
            'SLACK_BOT' => [
                'name' => 'Slack bot token',
                'regex' => '/xoxb-[0-9]{10,13}-[0-9]{10,13}-[A-Za-z0-9]{24}/',
            ],
            'SLACK_USER' => [
                'name' => 'Slack user token',
                'regex' => '/xoxp-[0-9]{10,13}-[0-9]{10,13}-[0-9]{10,13}-[A-Za-z0-9]{32}/',
            ],
            'AWS_ACCESS_KEY' => [
                'name' => 'AWS access key ID',
                'regex' => '/AKIA[A-Z0-9]{16}/',
            ],
            'STRIPE_SECRET' => [
                'name' => 'Stripe secret key (live)',
                'regex' => '/sk_live_[A-Za-z0-9]{24,}/',
            ],
            'STRIPE_TEST' => [
                'name' => 'Stripe secret key (test)',
                'regex' => '/sk_test_[A-Za-z0-9]{24,}/',
            ],
            'TELEGRAM_BOT' => [
                'name' => 'Telegram bot token',
                'regex' => '/[0-9]{8,10}:[A-Za-z0-9_\-]{35}/',
            ],
            'SENDGRID_KEY' => [
                'name' => 'SendGrid API key',
                'regex' => '/SG\.[A-Za-z0-9\-_]{22}\.[A-Za-z0-9\-_]{43}/',
            ],
            'TWILIO_KEY' => [
                'name' => 'Twilio API key',
                'regex' => '/SK[0-9a-f]{32}/',
            ],
            'HUGGINGFACE_TOKEN' => [
                'name' => 'HuggingFace API token',
                'regex' => '/hf_[A-Za-z0-9]{32,}/',
            ],
            'SHOPIFY_TOKEN' => [
                'name' => 'Shopify access token',
                'regex' => '/shpat_[A-Za-z0-9]{32}/',
            ],
            'GENERIC_PRIVATE_KEY' => [
                'name' => 'PEM private key block',
                'regex' => '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
            ],
        ];
    }

    /**
     * Scan a text string for all matching patterns.
     *
     * @return array<int, array{pattern_id: string, name: string}> List of findings (deduplicated by pattern_id).
     */
    public function scan(string $text): array
    {
        $findings = [];

        foreach ($this->patterns() as $patternId => $definition) {
            if (preg_match($definition['regex'], $text)) {
                $findings[] = [
                    'pattern_id' => $patternId,
                    'name' => $definition['name'],
                ];
            }
        }

        return $findings;
    }

    /**
     * Scan multiple text fields at once.
     *
     * @param  array<string, string>  $fields  Map of field_name => text content.
     * @return array<int, array{field: string, pattern_id: string, name: string}> Flat findings list.
     */
    public function scanFields(array $fields): array
    {
        $findings = [];

        foreach ($fields as $fieldName => $text) {
            if ($text === '') {
                continue;
            }

            foreach ($this->scan($text) as $finding) {
                $findings[] = array_merge(['field' => $fieldName], $finding);
            }
        }

        return $findings;
    }
}
