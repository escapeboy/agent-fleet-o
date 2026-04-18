<?php

namespace App\Domain\Assistant\Tools\Mutations;

use App\Domain\Email\Actions\CreateEmailTemplateAction;
use App\Domain\Email\Actions\CreateEmailThemeAction;
use App\Domain\Email\Actions\DeleteEmailTemplateAction;
use App\Domain\Email\Actions\DeleteEmailThemeAction;
use App\Domain\Email\Actions\UpdateEmailTemplateAction;
use App\Domain\Email\Actions\UpdateEmailThemeAction;
use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Email\Models\EmailTheme;
use App\Domain\Email\Services\MjmlRenderer;
use App\Models\GlobalSetting;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

final class AdminMutationTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function writeTools(): array
    {
        return [
            self::updateGlobalSettings(),
            self::createEmailTemplate(),
            self::updateEmailTemplate(),
            self::createEmailTheme(),
            self::updateEmailTheme(),
        ];
    }

    /**
     * @return array<PrismToolObject>
     */
    public static function destructiveTools(): array
    {
        return [
            self::deleteEmailTemplate(),
            self::deleteEmailTheme(),
        ];
    }

    public static function updateGlobalSettings(): PrismToolObject
    {
        $allowedKeys = [
            'assistant_llm_provider', 'assistant_llm_model',
            'default_llm_provider', 'default_llm_model',
            'budget_cap_credits', 'rate_limit_rpm',
            'outbound_rate_limit', 'experiment_timeout_seconds',
            'weekly_digest_enabled', 'audit_retention_days',
        ];

        return PrismTool::as('update_global_settings')
            ->for('Update global platform settings. Allowed keys: '.implode(', ', $allowedKeys).'. Returns previous and new values.')
            ->withStringParameter('settings_json', 'JSON object of setting key-value pairs to update. Example: {"default_llm_provider":"anthropic","budget_cap_credits":50000}', required: true)
            ->using(function (string $settings_json) use ($allowedKeys) {
                $settings = json_decode($settings_json, true);

                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($settings)) {
                    return json_encode(['error' => 'Invalid JSON: '.json_last_error_msg()]);
                }

                $unknownKeys = array_diff(array_keys($settings), $allowedKeys);
                if (! empty($unknownKeys)) {
                    return json_encode(['error' => 'Unknown keys: '.implode(', ', $unknownKeys).'. Allowed: '.implode(', ', $allowedKeys)]);
                }

                $updated = [];
                foreach ($settings as $key => $value) {
                    $previous = GlobalSetting::get($key);
                    GlobalSetting::set($key, $value);
                    $updated[$key] = ['previous' => $previous, 'new' => $value];
                }

                return json_encode(['success' => true, 'updated_count' => count($updated), 'changes' => $updated]);
            });
    }

    public static function createEmailTemplate(): PrismToolObject
    {
        return PrismTool::as('create_email_template')
            ->for('Create a new email template. Provide html_body (raw HTML) or mjml_body (MJML markup — compiled server-side to HTML). After creation, visit the builder URL to refine visually.')
            ->withStringParameter('name', 'Template name', required: true)
            ->withStringParameter('subject', 'Email subject line. Supports merge tags like {{first_name}}')
            ->withStringParameter('preview_text', 'Short inbox preview text (50–90 characters)')
            ->withStringParameter('html_body', 'Raw HTML content for the email body')
            ->withStringParameter('mjml_body', 'Complete MJML document (<mjml>...</mjml>). Compiled automatically to cross-client HTML. Preferred over html_body.')
            ->withStringParameter('status', 'Status: draft, active, archived (default: draft)')
            ->withStringParameter('visibility', 'Visibility: private, public (default: private)')
            ->withStringParameter('email_theme_id', 'UUID of the email theme to apply')
            ->using(function (
                string $name,
                ?string $subject = null,
                ?string $preview_text = null,
                ?string $html_body = null,
                ?string $mjml_body = null,
                ?string $status = null,
                ?string $visibility = null,
                ?string $email_theme_id = null,
            ) {
                try {
                    $team = auth()->user()->currentTeam;

                    // Sanitize LLM "None" strings for optional UUID fields
                    $email_theme_id = ($email_theme_id && Str::isUuid($email_theme_id)) ? $email_theme_id : null;

                    $data = array_filter([
                        'name' => $name,
                        'subject' => $subject,
                        'preview_text' => $preview_text,
                        'status' => $status ?? 'draft',
                        'visibility' => $visibility ?? 'private',
                        'email_theme_id' => $email_theme_id,
                    ], fn ($v) => $v !== null);

                    if ($mjml_body !== null) {
                        $data['html_cache'] = app(MjmlRenderer::class)->render($mjml_body);
                        $data['design_json'] = ['type' => 'mjml', 'source' => $mjml_body];
                    } elseif ($html_body !== null) {
                        $data['html_cache'] = $html_body;
                    }

                    $template = app(CreateEmailTemplateAction::class)->execute($team, $data);

                    return json_encode([
                        'success' => true,
                        'template_id' => $template->id,
                        'name' => $template->name,
                        'status' => $template->status->value,
                        'has_html_cache' => ! empty($template->html_cache),
                        'url' => route('email.templates.edit', $template),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function updateEmailTemplate(): PrismToolObject
    {
        return PrismTool::as('update_email_template')
            ->for('Update an existing email template metadata or body content. Provide html_body or mjml_body to set HTML content. Only supply fields you want to change — omitted fields are preserved.')
            ->withStringParameter('template_id', 'Email template UUID', required: true)
            ->withStringParameter('name', 'Template name')
            ->withStringParameter('subject', 'Email subject line. Supports merge tags like {{first_name}}')
            ->withStringParameter('preview_text', 'Short inbox preview text (50–90 characters)')
            ->withStringParameter('html_body', 'Raw HTML content for the email body')
            ->withStringParameter('mjml_body', 'Complete MJML document (<mjml>...</mjml>). Compiled automatically to cross-client HTML. Preferred over html_body.')
            ->withStringParameter('status', 'Status: draft, active, archived')
            ->withStringParameter('visibility', 'Visibility: private, public')
            ->withStringParameter('email_theme_id', 'UUID of the email theme to apply')
            ->using(function (
                string $template_id,
                ?string $name = null,
                ?string $subject = null,
                ?string $preview_text = null,
                ?string $html_body = null,
                ?string $mjml_body = null,
                ?string $status = null,
                ?string $visibility = null,
                ?string $email_theme_id = null,
            ) {
                $template = EmailTemplate::find($template_id);
                if (! $template) {
                    return json_encode(['error' => 'Email template not found']);
                }

                try {
                    // Sanitize LLM "None" strings for optional UUID fields
                    $email_theme_id = ($email_theme_id && Str::isUuid($email_theme_id)) ? $email_theme_id : null;

                    $data = array_filter([
                        'name' => $name,
                        'subject' => $subject,
                        'preview_text' => $preview_text,
                        'status' => $status,
                        'visibility' => $visibility,
                        'email_theme_id' => $email_theme_id,
                    ], fn ($v) => $v !== null);

                    if ($mjml_body !== null) {
                        $data['html_cache'] = app(MjmlRenderer::class)->render($mjml_body);
                        $data['design_json'] = ['type' => 'mjml', 'source' => $mjml_body];
                    } elseif ($html_body !== null) {
                        $data['html_cache'] = $html_body;
                    }

                    $template = app(UpdateEmailTemplateAction::class)->execute($template, $data);

                    return json_encode([
                        'success' => true,
                        'template_id' => $template->id,
                        'name' => $template->name,
                        'status' => $template->status->value,
                        'has_html_cache' => ! empty($template->html_cache),
                        'url' => route('email.templates.edit', $template),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function deleteEmailTemplate(): PrismToolObject
    {
        return PrismTool::as('delete_email_template')
            ->for('Delete an email template (soft delete). This is a destructive action — the template will be permanently removed from the list.')
            ->withStringParameter('template_id', 'Email template UUID', required: true)
            ->using(function (string $template_id) {
                $template = EmailTemplate::find($template_id);
                if (! $template) {
                    return json_encode(['error' => 'Email template not found']);
                }

                try {
                    $name = $template->name;
                    app(DeleteEmailTemplateAction::class)->execute($template);

                    return json_encode(['success' => true, 'message' => "Email template '{$name}' deleted."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function createEmailTheme(): PrismToolObject
    {
        return PrismTool::as('create_email_theme')
            ->for('Create a new email theme with brand colors, fonts, and footer info. All color/layout fields are optional with sensible defaults (blue primary, Inter font, 600px width).')
            ->withStringParameter('name', 'Theme name', required: true)
            ->withStringParameter('primary_color', 'Primary/CTA color as hex (e.g. #2563eb). Default: #2563eb')
            ->withStringParameter('background_color', 'Email background color as hex. Default: #f4f4f4')
            ->withStringParameter('canvas_color', 'Content area background color as hex. Default: #ffffff')
            ->withStringParameter('text_color', 'Body text color as hex. Default: #1f2937')
            ->withStringParameter('heading_color', 'Heading text color as hex. Default: #111827')
            ->withStringParameter('font_name', 'Font display name (e.g. Inter, Georgia). Default: Inter')
            ->withStringParameter('font_url', 'Google Fonts or web font URL for @import')
            ->withStringParameter('font_family', 'Full CSS font-family stack. Default: Inter, Arial, sans-serif')
            ->withStringParameter('logo_url', 'Absolute URL to the team logo image')
            ->withStringParameter('company_name', 'Company name shown in email footer')
            ->withStringParameter('company_address', 'Company address shown in email footer')
            ->withStringParameter('footer_text', 'Footer text or HTML (e.g. unsubscribe line)')
            ->using(function (
                string $name,
                ?string $primary_color = null,
                ?string $background_color = null,
                ?string $canvas_color = null,
                ?string $text_color = null,
                ?string $heading_color = null,
                ?string $font_name = null,
                ?string $font_url = null,
                ?string $font_family = null,
                ?string $logo_url = null,
                ?string $company_name = null,
                ?string $company_address = null,
                ?string $footer_text = null,
            ) {
                try {
                    $team = auth()->user()->currentTeam;

                    $data = array_filter([
                        'name' => $name,
                        'primary_color' => $primary_color,
                        'background_color' => $background_color,
                        'canvas_color' => $canvas_color,
                        'text_color' => $text_color,
                        'heading_color' => $heading_color,
                        'font_name' => $font_name,
                        'font_url' => $font_url,
                        'font_family' => $font_family,
                        'logo_url' => $logo_url,
                        'company_name' => $company_name,
                        'company_address' => $company_address,
                        'footer_text' => $footer_text,
                    ], fn ($v) => $v !== null);

                    $theme = app(CreateEmailThemeAction::class)->execute($team, $data);

                    return json_encode([
                        'success' => true,
                        'theme_id' => $theme->id,
                        'name' => $theme->name,
                        'status' => $theme->status->value,
                        'primary_color' => $theme->primary_color,
                        'font_name' => $theme->font_name,
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function updateEmailTheme(): PrismToolObject
    {
        return PrismTool::as('update_email_theme')
            ->for('Update an existing email theme. Only supply fields you want to change — omitted fields are preserved.')
            ->withStringParameter('theme_id', 'Email theme UUID', required: true)
            ->withStringParameter('name', 'Theme name')
            ->withStringParameter('status', 'Status: draft, active, archived')
            ->withStringParameter('primary_color', 'Primary/CTA color as hex (e.g. #2563eb)')
            ->withStringParameter('background_color', 'Email background color as hex')
            ->withStringParameter('canvas_color', 'Content area background color as hex')
            ->withStringParameter('text_color', 'Body text color as hex')
            ->withStringParameter('heading_color', 'Heading text color as hex')
            ->withStringParameter('font_name', 'Font display name (e.g. Inter, Georgia)')
            ->withStringParameter('font_url', 'Google Fonts or web font URL for @import')
            ->withStringParameter('font_family', 'Full CSS font-family stack')
            ->withStringParameter('logo_url', 'Absolute URL to the team logo image')
            ->withStringParameter('company_name', 'Company name shown in email footer')
            ->withStringParameter('company_address', 'Company address shown in email footer')
            ->withStringParameter('footer_text', 'Footer text or HTML')
            ->using(function (
                string $theme_id,
                ?string $name = null,
                ?string $status = null,
                ?string $primary_color = null,
                ?string $background_color = null,
                ?string $canvas_color = null,
                ?string $text_color = null,
                ?string $heading_color = null,
                ?string $font_name = null,
                ?string $font_url = null,
                ?string $font_family = null,
                ?string $logo_url = null,
                ?string $company_name = null,
                ?string $company_address = null,
                ?string $footer_text = null,
            ) {
                $theme = EmailTheme::find($theme_id);
                if (! $theme) {
                    return json_encode(['error' => 'Email theme not found']);
                }

                try {
                    $data = array_filter([
                        'name' => $name,
                        'status' => $status,
                        'primary_color' => $primary_color,
                        'background_color' => $background_color,
                        'canvas_color' => $canvas_color,
                        'text_color' => $text_color,
                        'heading_color' => $heading_color,
                        'font_name' => $font_name,
                        'font_url' => $font_url,
                        'font_family' => $font_family,
                        'logo_url' => $logo_url,
                        'company_name' => $company_name,
                        'company_address' => $company_address,
                        'footer_text' => $footer_text,
                    ], fn ($v) => $v !== null);

                    $theme = app(UpdateEmailThemeAction::class)->execute($theme, $data);

                    return json_encode([
                        'success' => true,
                        'theme_id' => $theme->id,
                        'name' => $theme->name,
                        'status' => $theme->status->value,
                        'primary_color' => $theme->primary_color,
                        'font_name' => $theme->font_name,
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function deleteEmailTheme(): PrismToolObject
    {
        return PrismTool::as('delete_email_theme')
            ->for('Delete an email theme (soft delete). This is a destructive action.')
            ->withStringParameter('theme_id', 'Email theme UUID', required: true)
            ->using(function (string $theme_id) {
                $theme = EmailTheme::find($theme_id);
                if (! $theme) {
                    return json_encode(['error' => 'Email theme not found']);
                }

                try {
                    $name = $theme->name;
                    app(DeleteEmailThemeAction::class)->execute($theme);

                    return json_encode(['success' => true, 'message' => "Email theme '{$name}' deleted."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }
}
