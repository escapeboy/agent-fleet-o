<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\Drivers\Airtable\AirtableIntegrationDriver;
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
use App\Domain\Integration\Drivers\Mailchimp\MailchimpIntegrationDriver;
use App\Domain\Integration\Drivers\Make\MakeIntegrationDriver;
use App\Domain\Integration\Drivers\Notion\NotionIntegrationDriver;
use App\Domain\Integration\Drivers\PagerDuty\PagerDutyIntegrationDriver;
use App\Domain\Integration\Drivers\Salesforce\SalesforceIntegrationDriver;
use App\Domain\Integration\Drivers\Sentry\SentryIntegrationDriver;
use App\Domain\Integration\Drivers\Slack\SlackIntegrationDriver;
use App\Domain\Integration\Drivers\Stripe\StripeIntegrationDriver;
use App\Domain\Integration\Drivers\Teams\TeamsIntegrationDriver;
use App\Domain\Integration\Drivers\Telegram\TelegramIntegrationDriver;
use App\Domain\Integration\Drivers\WhatsApp\WhatsAppIntegrationDriver;
use App\Domain\Integration\Drivers\Zapier\ZapierIntegrationDriver;
use App\Domain\Integration\Drivers\Typeform\TypeformIntegrationDriver;
use App\Domain\Integration\Drivers\Calendly\CalendlyIntegrationDriver;
use App\Domain\Integration\Drivers\PostHog\PostHogIntegrationDriver;
use App\Domain\Integration\Drivers\Attio\AttioIntegrationDriver;
use App\Domain\Integration\Drivers\Freshdesk\FreshdeskIntegrationDriver;
use App\Domain\Integration\Drivers\Segment\SegmentIntegrationDriver;
use App\Domain\Integration\Drivers\GitLab\GitLabIntegrationDriver;
use App\Domain\Integration\Drivers\Shopify\ShopifyIntegrationDriver;
use App\Domain\Integration\Drivers\ClickUp\ClickUpIntegrationDriver;
use App\Domain\Integration\Drivers\Pipedrive\PipedriveIntegrationDriver;
use App\Domain\Integration\Drivers\Confluence\ConfluenceIntegrationDriver;
use App\Domain\Integration\Drivers\Bitbucket\BitbucketIntegrationDriver;
use App\Domain\Integration\Drivers\Zendesk\ZendeskIntegrationDriver;
use App\Domain\Integration\Drivers\Intercom\IntercomIntegrationDriver;
use App\Domain\Integration\Drivers\Monday\MondayIntegrationDriver;
use App\Domain\Integration\Drivers\Asana\AsanaIntegrationDriver;
use App\Domain\Integration\Drivers\Twilio\TwilioIntegrationDriver;
||||||| 0213205
use App\Domain\Integration\Drivers\Teams\TeamsIntegrationDriver;
use App\Domain\Integration\Drivers\Telegram\TelegramIntegrationDriver;
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
 * New integrations are added by:
 *   1. Implementing IntegrationDriverInterface
 *   2. Adding a createXxxDriver() method here
 *   3. Registering the driver slug in config/integrations.php
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

    public function createTypeformDriver(): IntegrationDriverInterface
    {
        return $this->container->make(TypeformIntegrationDriver::class);
    }

    public function createCalendlyDriver(): IntegrationDriverInterface
    {
        return $this->container->make(CalendlyIntegrationDriver::class);
    }

    public function createPosthogDriver(): IntegrationDriverInterface
    {
        return $this->container->make(PostHogIntegrationDriver::class);
    }

    public function createAttioDriver(): IntegrationDriverInterface
    {
        return $this->container->make(AttioIntegrationDriver::class);
    }

    public function createFreshdeskDriver(): IntegrationDriverInterface
    {
        return $this->container->make(FreshdeskIntegrationDriver::class);
    }

    public function createSegmentDriver(): IntegrationDriverInterface
    {
        return $this->container->make(SegmentIntegrationDriver::class);
    }

    public function createGitlabDriver(): IntegrationDriverInterface
    {
        return $this->container->make(GitLabIntegrationDriver::class);
    }

    public function createShopifyDriver(): IntegrationDriverInterface
    {
        return $this->container->make(ShopifyIntegrationDriver::class);
    }

    public function createClickupDriver(): IntegrationDriverInterface
    {
        return $this->container->make(ClickUpIntegrationDriver::class);
    }

    public function createPipedriveDriver(): IntegrationDriverInterface
    {
        return $this->container->make(PipedriveIntegrationDriver::class);
    }

    public function createConfluenceDriver(): IntegrationDriverInterface
    {
        return $this->container->make(ConfluenceIntegrationDriver::class);
    }

    public function createBitbucketDriver(): IntegrationDriverInterface
    {
        return $this->container->make(BitbucketIntegrationDriver::class);
    }

    public function createZendeskDriver(): IntegrationDriverInterface
    {
        return $this->container->make(ZendeskIntegrationDriver::class);
    }

    public function createIntercomDriver(): IntegrationDriverInterface
    {
        return $this->container->make(IntercomIntegrationDriver::class);
    }

    public function createMondayDriver(): IntegrationDriverInterface
    {
        return $this->container->make(MondayIntegrationDriver::class);
    }

    public function createAsanaDriver(): IntegrationDriverInterface
    {
        return $this->container->make(AsanaIntegrationDriver::class);
    }

    public function createTwilioDriver(): IntegrationDriverInterface
    {
        return $this->container->make(TwilioIntegrationDriver::class);
    }
||||||| 0213205

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
}
