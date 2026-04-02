<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\Drivers\Activepieces\ActivepiecesIntegrationDriver;
use App\Domain\Integration\Drivers\Airtable\AirtableIntegrationDriver;
use App\Domain\Integration\Drivers\Apify\ApifyIntegrationDriver;
use App\Domain\Integration\Drivers\Datadog\DatadogIntegrationDriver;
use App\Domain\Integration\Drivers\Discord\DiscordIntegrationDriver;
use App\Domain\Integration\Drivers\Generic\ApiPollingDriver;
use App\Domain\Integration\Drivers\Generic\WebhookOnlyDriver;
use App\Domain\Integration\Drivers\GitHub\GitHubIntegrationDriver;
use App\Domain\Integration\Drivers\Google\GoogleIntegrationDriver;
use App\Domain\Integration\Drivers\HubSpot\HubSpotIntegrationDriver;
use App\Domain\Integration\Drivers\Jira\JiraIntegrationDriver;
use App\Domain\Integration\Drivers\Klaviyo\KlaviyoIntegrationDriver;
use App\Domain\Integration\Drivers\Linear\LinearIntegrationDriver;
use App\Domain\Integration\Drivers\LinkedIn\LinkedInIntegrationDriver;
use App\Domain\Integration\Drivers\LiveKit\LiveKitIntegrationDriver;
use App\Domain\Integration\Drivers\Mailchimp\MailchimpIntegrationDriver;
use App\Domain\Integration\Drivers\Make\MakeIntegrationDriver;
use App\Domain\Integration\Drivers\Netlify\NetlifyIntegrationDriver;
use App\Domain\Integration\Drivers\Notion\NotionIntegrationDriver;
use App\Domain\Integration\Drivers\OnePassword\OnePasswordIntegrationDriver;
use App\Domain\Integration\Drivers\PagerDuty\PagerDutyIntegrationDriver;
use App\Domain\Integration\Drivers\Salesforce\SalesforceIntegrationDriver;
use App\Domain\Integration\Drivers\Sentry\SentryIntegrationDriver;
use App\Domain\Integration\Drivers\Slack\SlackIntegrationDriver;
use App\Domain\Integration\Drivers\SshDeploy\SshDeployIntegrationDriver;
use App\Domain\Integration\Drivers\Stripe\StripeIntegrationDriver;
use App\Domain\Integration\Drivers\Supabase\SupabaseIntegrationDriver;
use App\Domain\Integration\Drivers\Teams\TeamsIntegrationDriver;
use App\Domain\Integration\Drivers\Telegram\TelegramIntegrationDriver;
use App\Domain\Integration\Drivers\Twitter\TwitterIntegrationDriver;
use App\Domain\Integration\Drivers\Vercel\VercelIntegrationDriver;
use App\Domain\Integration\Drivers\WhatsApp\WhatsAppIntegrationDriver;
use App\Domain\Integration\Drivers\Zapier\ZapierIntegrationDriver;
use Illuminate\Support\Manager;

/**
 * Laravel Manager that resolves integration drivers by slug.
 *
 * Usage:
 *   $driver = app(IntegrationManager::class)->driver('github');
 *   $driver = app(IntegrationManager::class)->driver('slack');
 *
 * Adding integrations:
 *   Core: add a createXxxDriver() method here + register slug in config/integrations.php
 *   Plugin: use FleetPluginServiceProvider::$integrations array — auto-extends this Manager
 */
class IntegrationManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return config('integrations.default', 'webhook');
    }

    public function createApiPollingDriver(): IntegrationDriverInterface
    {
        return $this->container->make(ApiPollingDriver::class);
    }

    public function createWebhookDriver(): IntegrationDriverInterface
    {
        return $this->container->make(WebhookOnlyDriver::class);
    }

    public function createGithubDriver(): IntegrationDriverInterface
    {
        return $this->container->make(GitHubIntegrationDriver::class);
    }

    public function createSlackDriver(): IntegrationDriverInterface
    {
        return $this->container->make(SlackIntegrationDriver::class);
    }

    public function createStripeDriver(): IntegrationDriverInterface
    {
        return $this->container->make(StripeIntegrationDriver::class);
    }

    public function createNotionDriver(): IntegrationDriverInterface
    {
        return $this->container->make(NotionIntegrationDriver::class);
    }

    public function createAirtableDriver(): IntegrationDriverInterface
    {
        return $this->container->make(AirtableIntegrationDriver::class);
    }

    public function createLinearDriver(): IntegrationDriverInterface
    {
        return $this->container->make(LinearIntegrationDriver::class);
    }

    public function createDiscordDriver(): IntegrationDriverInterface
    {
        return $this->container->make(DiscordIntegrationDriver::class);
    }

    public function createTeamsDriver(): IntegrationDriverInterface
    {
        return $this->container->make(TeamsIntegrationDriver::class);
    }

    public function createWhatsappDriver(): IntegrationDriverInterface
    {
        return $this->container->make(WhatsAppIntegrationDriver::class);
    }

    public function createTelegramDriver(): IntegrationDriverInterface
    {
        return $this->container->make(TelegramIntegrationDriver::class);
    }

    public function createDatadogDriver(): IntegrationDriverInterface
    {
        return $this->container->make(DatadogIntegrationDriver::class);
    }

    public function createSentryDriver(): IntegrationDriverInterface
    {
        return $this->container->make(SentryIntegrationDriver::class);
    }

    public function createPagerdutyDriver(): IntegrationDriverInterface
    {
        return $this->container->make(PagerDutyIntegrationDriver::class);
    }

    public function createHubspotDriver(): IntegrationDriverInterface
    {
        return $this->container->make(HubSpotIntegrationDriver::class);
    }

    public function createSalesforceDriver(): IntegrationDriverInterface
    {
        return $this->container->make(SalesforceIntegrationDriver::class);
    }

    public function createMailchimpDriver(): IntegrationDriverInterface
    {
        return $this->container->make(MailchimpIntegrationDriver::class);
    }

    public function createKlaviyoDriver(): IntegrationDriverInterface
    {
        return $this->container->make(KlaviyoIntegrationDriver::class);
    }

    public function createGoogleDriver(): IntegrationDriverInterface
    {
        return $this->container->make(GoogleIntegrationDriver::class);
    }

    public function createJiraDriver(): IntegrationDriverInterface
    {
        return $this->container->make(JiraIntegrationDriver::class);
    }

    public function createZapierDriver(): IntegrationDriverInterface
    {
        return $this->container->make(ZapierIntegrationDriver::class);
    }

    public function createMakeDriver(): IntegrationDriverInterface
    {
        return $this->container->make(MakeIntegrationDriver::class);
    }

    public function createSupabaseDriver(): IntegrationDriverInterface
    {
        return $this->container->make(SupabaseIntegrationDriver::class);
    }

    public function createLinkedinDriver(): IntegrationDriverInterface
    {
        return $this->container->make(LinkedInIntegrationDriver::class);
    }

    public function createTwitterDriver(): IntegrationDriverInterface
    {
        return $this->container->make(TwitterIntegrationDriver::class);
    }

    public function createVercelDriver(): IntegrationDriverInterface
    {
        return $this->container->make(VercelIntegrationDriver::class);
    }

    public function createNetlifyDriver(): IntegrationDriverInterface
    {
        return $this->container->make(NetlifyIntegrationDriver::class);
    }

    public function createSshDeployDriver(): IntegrationDriverInterface
    {
        return $this->container->make(SshDeployIntegrationDriver::class);
    }

    public function createActivepiecesDriver(): IntegrationDriverInterface
    {
        return $this->container->make(ActivepiecesIntegrationDriver::class);
    }

    public function createLivekitDriver(): IntegrationDriverInterface
    {
        return $this->container->make(LiveKitIntegrationDriver::class);
    }

    public function createApifyDriver(): IntegrationDriverInterface
    {
        return $this->container->make(ApifyIntegrationDriver::class);
    }

    public function create1passwordDriver(): IntegrationDriverInterface
    {
        return $this->container->make(OnePasswordIntegrationDriver::class);
    }
}
