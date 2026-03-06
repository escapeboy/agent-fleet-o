<?php

namespace App\Domain\Email\Actions;

use App\Domain\Email\Models\EmailTheme;
use App\Domain\Email\Services\MjmlRenderer;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Facades\Log;

class GenerateEmailTemplateAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
        private readonly MjmlRenderer $mjmlRenderer,
    ) {}

    /**
     * Generate MJML email markup from a natural language description.
     * Does NOT write to the database — caller decides whether to persist.
     *
     * @return array{mjml_source: string, html_preview: string, subject_suggestion: string}
     */
    public function execute(
        string $description,
        ?EmailTheme $theme = null,
        string $tone = 'professional',
        ?string $teamId = null,
    ): array {
        $systemPrompt = $this->buildSystemPrompt($theme, $tone);
        $resolved = $this->providerResolver->resolve();

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: $resolved['provider'],
            model: $resolved['model'],
            systemPrompt: $systemPrompt,
            userPrompt: $description,
            maxTokens: 8192,
            temperature: 0.4,
            teamId: $teamId,
            purpose: 'email_template_generation',
        ));

        $mjml = $this->extractMjml($response->content);
        $subjectSuggestion = $this->extractSubjectSuggestion($response->content, $description);

        $html = $this->mjmlRenderer->render($mjml);

        return [
            'mjml_source' => $mjml,
            'html_preview' => $html,
            'subject_suggestion' => $subjectSuggestion,
        ];
    }

    private function buildSystemPrompt(?EmailTheme $theme, string $tone): string
    {
        $brandSection = '';
        if ($theme) {
            $brandSection = <<<BRAND

## Brand Variables (apply these to the generated email)
primary_color: {$theme->primary_color}
font_family: "{$theme->font_name}", Arial, Helvetica, sans-serif
company_name: {$theme->company_name}
footer_text: {$theme->footer_text}
BRAND;

            if ($theme->logo_url) {
                $brandSection .= "\nlogo_url: {$theme->logo_url}";
            }
        }

        return <<<PROMPT
You are an expert email template generator. Output ONLY valid MJML markup — no markdown fences, no explanation.

Before the MJML, output exactly one line:
SUBJECT: <suggested email subject line>

Then output the complete MJML document starting with <mjml>.

## Tone
{$tone}

## Allowed MJML Components
<mjml><mj-head><mj-attributes><mj-font name="" href=""><mj-body>
<mj-section background-color="" padding=""><mj-column width="">
<mj-text font-size="" font-family="" color="" align="" padding="" line-height="">
<mj-image src="" alt="" width="" href="" align="">
<mj-button background-color="" color="" font-size="" href="" align="" border-radius="" padding="">
<mj-divider border-color="" border-width="" padding="">
<mj-spacer height="">
<mj-social><mj-social-element name="twitter|facebook|linkedin|instagram">

## Constraints
- No CSS Grid, no Flexbox — email clients don't support them
- All colors in hex (#rrggbb) format only
- Font stacks must include web-safe fallback: Arial, Helvetica, sans-serif
- All hrefs: absolute https:// URLs or merge tags like {{unsubscribe_url}}
- Use merge tags for dynamic content: {{first_name}}, {{company}}, {{unsubscribe_url}}
- Maximum content width: 600px
- Always include an unsubscribe link in the footer using {{unsubscribe_url}}{$brandSection}
PROMPT;
    }

    private function extractMjml(string $content): string
    {
        // Strip the SUBJECT line if present
        $content = preg_replace('/^SUBJECT:.*$/m', '', $content);
        $content = trim($content);

        // Strip markdown code fences if LLM wraps in them anyway
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:mjml|html|xml)?\s*/i', '', $content);
            $content = preg_replace('/\s*```\s*$/', '', $content);
            $content = trim($content);
        }

        // Extract the <mjml>...</mjml> block
        if (preg_match('/<mjml[\s>].*<\/mjml>/is', $content, $matches)) {
            return trim($matches[0]);
        }

        Log::warning('GenerateEmailTemplateAction: no <mjml> block found in LLM response', [
            'preview' => substr($content, 0, 300),
        ]);

        return $content;
    }

    private function extractSubjectSuggestion(string $content, string $description): string
    {
        if (preg_match('/^SUBJECT:\s*(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        // Fallback: derive from description
        return ucfirst(mb_substr($description, 0, 80));
    }
}
