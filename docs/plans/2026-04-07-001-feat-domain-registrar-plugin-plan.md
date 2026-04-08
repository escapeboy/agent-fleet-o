# Plan: FleetQ Domain Registrar Plugin

**Date:** 2026-04-07
**Status:** approved
**Repo:** `fleetq-plugin-domain` (new) + cleanup in `base/`

---

## Summary

Standalone Composer plugin that provides provider-agnostic domain registration, DNS management, and renewal billing via Stripe. Ships in a separate repo (`/Users/katsarov/htdocs/fleetq-plugin-domain/`), installed into the cloud `agent-fleet/` app.

---

## Architecture

### Plugin Package Structure

```
fleetq-plugin-domain/
├── composer.json
├── config/
│   └── domain.php
├── database/migrations/
│   ├── 2026_04_07_000001_create_registered_domains_table.php
│   └── 2026_04_07_000002_add_registrant_contact_to_users_table.php
├── resources/views/livewire/
│   ├── domain-search.blade.php
│   ├── domain-checkout.blade.php
│   └── domain-manager.blade.php
├── routes/
│   └── api.php
└── src/
    ├── DomainPlugin.php
    ├── DomainPluginServiceProvider.php
    ├── Contracts/
    │   └── DomainRegistrarInterface.php
    ├── DTOs/
    │   ├── RegistrantContactDTO.php
    │   ├── DomainAvailabilityResultDTO.php
    │   └── DnsHostRecordDTO.php
    ├── Enums/
    │   ├── DomainStatus.php
    │   └── DnsRecordType.php
    ├── Models/
    │   └── RegisteredDomain.php
    ├── Providers/Namecheap/
    │   ├── NamecheapClient.php
    │   └── NamecheapRegistrar.php
    ├── Actions/
    │   ├── CheckDomainAvailabilityAction.php
    │   ├── PurchaseDomainAction.php
    │   ├── ConfigureDnsAction.php
    │   └── RenewDomainAction.php
    ├── Http/Controllers/Api/
    │   └── DomainController.php
    ├── Livewire/
    │   ├── DomainSearchPage.php
    │   ├── DomainCheckoutPage.php
    │   └── DomainManagerPage.php
    ├── Jobs/
    │   └── ProcessDomainAutoRenewalJob.php
    ├── Console/Commands/
    │   └── CheckDomainExpiriesCommand.php
    └── Mcp/
        ├── DomainCheckTool.php
        ├── DomainPurchaseTool.php
        └── DomainDnsTool.php
```

---

## Key Interfaces

### DomainRegistrarInterface

```php
interface DomainRegistrarInterface
{
    // Returns: ['available' => bool, 'domain' => string, 'provider_price' => float|null]
    public function checkAvailability(string $domain): array;

    // Returns: ['success' => bool, 'provider_domain_id' => string|null, 'expires_at' => Carbon|null]
    public function register(string $domain, RegistrantContactDTO $contact, int $years = 1): array;

    // Returns: ['success' => bool, 'expires_at' => Carbon|null]
    public function renew(string $domain, string $providerDomainId, int $years = 1): array;

    // $records = DnsHostRecordDTO[]
    public function setDnsRecords(string $sld, string $tld, array $records): bool;

    // Returns: DnsHostRecordDTO[]
    public function getDnsRecords(string $sld, string $tld): array;

    public function getDriverName(): string; // 'namecheap', 'opensrs', etc.
}
```

---

## Database Schema

### `registered_domains`

| Column | Type | Notes |
|--------|------|-------|
| id | uuid PK | UUIDv7 |
| team_id | uuid FK | BelongsToTeam + TeamScope |
| user_id | uuid FK | purchaser |
| website_id | uuid FK nullable | linked website |
| domain | varchar(253) unique | full domain |
| tld | varchar(20) | e.g. 'com' |
| sld | varchar(63) | e.g. 'example' |
| provider | varchar(32) | 'namecheap', 'opensrs' |
| provider_domain_id | varchar(128) nullable | provider's ID |
| status | enum | pending/active/expired/transferred/cancelled |
| registered_at | timestamp nullable | |
| expires_at | timestamp nullable | |
| auto_renew | boolean | default false |
| stripe_subscription_id | varchar(128) nullable | for auto-renew |
| registrant_contact | jsonb | WHOIS snapshot at purchase |
| years_purchased | smallint | default 1 |
| provider_cost_usd | decimal(10,2) nullable | cost from registrar |
| charged_usd | decimal(10,2) nullable | user paid incl. margin |

Indexes: `(team_id, status)`, `(expires_at, auto_renew)` partial where status='active'

### `users` (migration adds column)
- `registrant_contact` jsonb nullable — prefill data for checkout form

---

## Configuration (`config/domain.php`)

```php
'provider' => env('DOMAIN_PROVIDER', 'namecheap'),
'margin_percent' => env('DOMAIN_MARGIN_PERCENT', 20),

'namecheap' => [
    'api_user'   => env('NAMECHEAP_API_USER'),
    'api_key'    => env('NAMECHEAP_API_KEY'),
    'username'   => env('NAMECHEAP_USERNAME'),
    'client_ip'  => env('NAMECHEAP_CLIENT_IP'),
    'sandbox'    => env('NAMECHEAP_SANDBOX', false),
],
```

Super admin can override `margin_percent` via `GlobalSettings` key `domain.margin_percent`.

---

## Flows

### Purchase Flow
1. `DomainSearchPage` → `CheckDomainAvailabilityAction` → display price with margin applied
2. `DomainCheckoutPage` — WHOIS form (prefilled from `user->registrant_contact`)
3. Stripe `PaymentIntent` created (one-time) OR `Subscription` (if auto_renew)
4. On Stripe webhook `payment_intent.succeeded` / `invoice.paid`:
   - `PurchaseDomainAction` → `DomainRegistrarInterface::register()`
   - Create `RegisteredDomain` (status: pending → active)
   - Save contact back to `user->registrant_contact` if user opted in

### DNS Management Flow
- `DomainManagerPage` lists `RegisteredDomain` records for team
- User edits host records → `ConfigureDnsAction` → `DomainRegistrarInterface::setDnsRecords()`

### Auto-Renew Flow
- Stripe subscription (annual) fires `invoice.paid`
- `ProcessDomainAutoRenewalJob` → `RenewDomainAction` → `DomainRegistrarInterface::renew()`
- Updates `expires_at` on `RegisteredDomain`

---

## Code to Remove from `base/`

After plugin is working:
- `app/Domain/Website/Services/NamecheapClient.php`
- `app/Domain/Website/Services/NamecheapClientFactory.php`
- `app/Domain/Website/Actions/Domain/` (all 3 actions)
- `app/Http/Controllers/Api/V1/DomainController.php`
- `app/Mcp/Tools/Website/DomainCheckTool.php`
- `app/Mcp/Tools/Website/DomainPurchaseTool.php`
- `app/Mcp/Tools/Website/DomainDnsTool.php`
- Domain routes in `routes/api_v1.php`
- Domain tool registrations in `AgentFleetServer.php`
- `tests/Feature/Domain/Website/DomainTest.php`

---

## Task List

| # | Task |
|---|------|
| 1 | Scaffold plugin package (composer.json, DomainPlugin, DomainPluginServiceProvider) |
| 2 | DomainRegistrarInterface + DTOs + Enums |
| 3 | NamecheapClient + NamecheapRegistrar |
| 4 | RegisteredDomain model + migrations |
| 5 | config/domain.php |
| 6 | Actions (Check, Purchase, ConfigureDns, Renew) |
| 7 | DomainController + routes/api.php |
| 8 | Livewire pages (Search, Checkout, Manager) + Blade views |
| 9 | MCP tools (Check, Purchase, Dns) |
| 10 | Stripe webhook handlers + ProcessDomainAutoRenewalJob |
| 11 | CheckDomainExpiriesCommand |
| 12 | Feature tests |
| 13 | Remove old code from base/ |
