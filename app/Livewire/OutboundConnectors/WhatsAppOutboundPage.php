<?php

namespace App\Livewire\OutboundConnectors;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

/**
 * Livewire component for configuring the WhatsApp Cloud API outbound connector.
 *
 * Credentials are stored encrypted in OutboundConnectorConfig (channel=whatsapp).
 * The access_token field is write-only: it is preserved on save when left blank.
 */
class WhatsAppOutboundPage extends Component
{
    /** Meta Phone Number ID (numeric string). */
    public string $phoneNumberId = '';

    /** Meta WhatsApp Business Account ID (for reference/webhook config). */
    public string $businessAccountId = '';

    /** Meta permanent system user access token (write-only). */
    public string $accessToken = '';

    /** Webhook verify token — sent to Meta during webhook registration. */
    public string $verifyToken = '';

    /** Whether this connector is enabled. */
    public bool $isActive = true;

    public ?string $lastTestedAt = null;

    public ?string $lastTestStatus = null;

    public ?string $testMessage = null;

    public ?string $testError = null;

    public function mount(): void
    {
        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'whatsapp')
            ->first();

        if ($config) {
            $creds = $config->credentials ?? [];
            $this->phoneNumberId = $creds['phone_number_id'] ?? '';
            $this->businessAccountId = $creds['business_account_id'] ?? '';
            $this->verifyToken = $creds['verify_token'] ?? '';
            // access_token is never pre-filled (write-only)
            $this->isActive = (bool) $config->is_active;
            $this->lastTestedAt = $config->last_tested_at?->diffForHumans();
            $this->lastTestStatus = $config->last_test_status;
        }
    }

    public function save(): void
    {
        $this->validate([
            'phoneNumberId' => 'required|string|max:64',
            'businessAccountId' => 'nullable|string|max:64',
            'verifyToken' => 'nullable|string|max:255',
        ]);

        $team = auth()->user()->currentTeam;

        $existing = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'whatsapp')
            ->first();

        $credentials = [
            'phone_number_id' => $this->phoneNumberId,
            'business_account_id' => $this->businessAccountId ?: null,
            'verify_token' => $this->verifyToken ?: null,
        ];

        // Preserve existing access_token when the field is left blank
        if ($this->accessToken) {
            $credentials['access_token'] = $this->accessToken;
        } elseif ($existing) {
            $credentials['access_token'] = $existing->credentials['access_token'] ?? '';
        } else {
            $credentials['access_token'] = '';
        }

        OutboundConnectorConfig::updateOrCreate(
            ['team_id' => $team->id, 'channel' => 'whatsapp'],
            ['credentials' => $credentials, 'is_active' => $this->isActive],
        );

        $this->accessToken = '';
        $this->testMessage = null;
        $this->testError = null;

        session()->flash('message', 'WhatsApp connector saved successfully.');
    }

    /**
     * Test the connector by hitting the Meta Graph API phone number endpoint.
     * A successful response confirms the access token and phone number ID are valid.
     */
    public function testConnection(): void
    {
        $team = auth()->user()->currentTeam;
        $config = OutboundConnectorConfig::where('team_id', $team->id)
            ->where('channel', 'whatsapp')
            ->first();

        if (! $config) {
            $this->testError = 'Save the connector first before testing.';

            return;
        }

        $creds = $config->credentials ?? [];
        $phoneNumberId = $creds['phone_number_id'] ?? '';
        $accessToken = $creds['access_token'] ?? '';

        if (! $phoneNumberId || ! $accessToken) {
            $this->testError = 'Phone Number ID and Access Token are required before testing.';

            return;
        }

        try {
            $response = Http::timeout(10)
                ->withToken($accessToken)
                ->get("https://graph.facebook.com/v21.0/{$phoneNumberId}", [
                    'fields' => 'id,display_phone_number,status',
                ]);

            $status = $response->successful() ? 'success' : 'failed';
        } catch (\Throwable) {
            $status = 'failed';
        }

        $config->update([
            'last_tested_at' => now(),
            'last_test_status' => $status,
        ]);

        $this->lastTestedAt = 'just now';
        $this->lastTestStatus = $status;

        if ($status === 'success') {
            $phoneDisplay = $response->json('display_phone_number') ?? $phoneNumberId;
            $this->testMessage = "Connected successfully. Phone: {$phoneDisplay}";
            $this->testError = null;
        } else {
            $errorMsg = $response->json('error.message') ?? 'Unknown error';
            $this->testError = "Meta API error: {$errorMsg}";
            $this->testMessage = null;
        }
    }

    public function render(): View
    {
        return view('livewire.outbound-connectors.whatsapp-outbound-page')
            ->layout('layouts.app', ['header' => 'WhatsApp Delivery']);
    }
}
