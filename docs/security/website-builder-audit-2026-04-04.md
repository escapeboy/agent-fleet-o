# Website Builder Security Audit — 2026-04-04

Reviewer: Security Engineer (Claude Sonnet 4.6)
Scope: Website Builder feature — controllers, actions, services, MCP tools, routes

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH     | 2 |
| MEDIUM   | 3 |
| LOW      | 2 |

---

## HIGH

### H1 — Stored XSS via raw HTML/CSS served to browsers with no Content-Security-Policy

**File:** `app/Domain/Website/Services/GrapesJsExporter.php`, `app/Http/Controllers/PublicSiteController.php`

`toStandaloneHtml()` injects `$html` and `$css` — both user-controlled, stored in `exported_html` / `exported_css` — directly into the response document body and `<style>` block with no sanitization. The `<title>` and `<meta description>` values are correctly escaped with `htmlspecialchars()`, but the body HTML and inline CSS are not. Any authenticated team member who can publish a page can embed arbitrary `<script>` tags in `exported_html`. These execute in the visitor's browser when the public page is served.

The `PublicSiteController::page()` response header is:
```
Content-Type: text/html; charset=utf-8
```
There is no `Content-Security-Policy` header, no `X-Content-Type-Options`, and no `X-Frame-Options` header on this response. This is the highest-severity exploitable path: a malicious team member (or a compromised account) can use any published page as a persistent XSS delivery endpoint, fully undetected.

**Remediation:**
1. Add a strict `Content-Security-Policy` response header on all `PublicSiteController` responses — at minimum `default-src 'self'; script-src 'none'` for pages that do not need external scripts, or a nonce-based policy for dynamic content.
2. Add `X-Frame-Options: SAMEORIGIN` and `X-Content-Type-Options: nosniff`.
3. If the product requirement is that publishers can embed arbitrary scripts (e.g. analytics), scope that capability to verified/owner roles only and document the intentional trust boundary.

---

### H2 — No rate limiting on the public form submission endpoint

**File:** `routes/api.php` lines 129–134

The three public site routes are registered inside a route group. The `submitForm` POST endpoint (`POST /api/public/sites/{siteSlug}/forms/{formId}`) has **no `throttle` middleware**. Every other unauthenticated POST in the codebase (Telegram webhook, WhatsApp webhook, integration webhooks) carries an explicit `throttle:N,1` annotation. The form submission endpoint does not.

This allows an attacker to flood a specific form endpoint with arbitrary payloads at unlimited rate, creating an unbounded number of `Signal` records (written to the `signals` table) and potentially triggering downstream workflows and outbound sends for each submission — directly exhausting the team's usage quota and credits.

The GET endpoints (`pages`, `page`) appear to be covered by a parent group throttle (the indexed output truncates around line 130), but this needs confirmation. The POST is definitively unprotected based on the route definition.

**Remediation:**
Apply `->middleware('throttle:20,1')` (20 req/min per IP) to the `submitForm` route. Consider a stricter per-form-per-IP limit using a custom throttle key.

---

## MEDIUM

### M1 — No cross-website ownership check on WebsitePage route model binding

**File:** `app/Http/Controllers/Api/V1/WebsitePageController.php`

Routes are defined as `/websites/{website}/pages/{page}`. Laravel resolves `{website}` and `{page}` independently via route model binding. Both models use `BelongsToTeam` + `TeamScope`, so a user cannot reference a `{website}` or `{page}` belonging to another team. However, there is **no check that the resolved `$page` actually belongs to the resolved `$website`**.

An authenticated user with two websites (Website A and Website B) could call:
```
PUT /api/v1/websites/{websiteA-id}/pages/{pageFromWebsiteB-id}
```
If both are in the same team, `TeamScope` passes for both, the route resolves, and the action modifies the page from Website B. This is an IDOR within-team, which matters in larger teams (admin/member separation) and is a correctness bug regardless.

**Remediation:**
Add an ownership guard in each `WebsitePageController` method:
```php
abort_if($page->website_id !== $website->id, 404);
```

---

### M2 — `submitForm` payload is unbounded: no field count or size limit

**File:** `app/Http/Controllers/PublicSiteController.php` — `submitForm()`

The only validation on the payload is `'payload' => ['required', 'array']`. An attacker can submit a payload with thousands of keys or megabyte-sized string values. Each submission is stored as a Signal record (JSONB column). There is no max-key-count, no per-key `max:` rule, and no total-size guard.

This enables storage exhaustion at the database level and could cause oversized Signal payloads to propagate through workflow triggers into LLM prompts (prompt injection via form fields).

**Remediation:**
```php
'payload' => ['required', 'array', 'max:50'],
'payload.*' => ['string', 'max:1000'],
```

---

### M3 — AI generate endpoint has no prompt length limit in the API controller

**File:** `app/Http/Controllers/Api/V1/WebsiteController.php` — `generate()`

The MCP tool `WebsiteGenerateTool` correctly validates `'prompt' => 'required|string|max:2000'`. The API controller's `generate()` method (called via `POST /api/v1/websites/generate`) passes the prompt directly to `GenerateWebsiteFromPromptAction` with no `$request->validate()` call visible in the indexed output. If there is no validation in the controller, a user can submit arbitrarily long prompts through the REST API, bypassing the MCP tool's `max:2000` guard and consuming unrestricted LLM tokens/credits per request.

**Remediation:**
Add to the `generate()` method:
```php
$request->validate(['prompt' => ['required', 'string', 'max:2000']]);
```
Confirm the limit matches the MCP tool and the budget enforcement layer.

---

## LOW

### L1 — ZIP temp file uses `storage_path('app/tmp/...')` — temp files not cleaned on failure

**File:** `app/Domain/Website/Services/WebsiteZipBuilder.php`

The ZIP is written to `storage/app/tmp/website-{uuid}-{timestamp}.zip`. Laravel's `deleteFileAfterSend()` cleans the file after a successful response. However, if an exception is thrown during `build()` or before the response is sent, the temp file is never deleted. This is a low-severity disk-leak rather than a security issue, but in a multi-tenant environment with many exports, abandoned ZIPs accumulate.

The `page->slug` is used as the ZIP entry filename (`{$page->slug}/index.html`). The slug is validated to `/^[a-z0-9-]+$/` in the page creation controller, so path traversal via slug is not possible in practice. No issue there.

**Remediation:** Wrap `build()` calls in a `try/finally` and delete the temp file if it exists on exception.

---

### L2 — `formId` route parameter is not validated as UUID or safe string

**File:** `app/Http/Controllers/PublicSiteController.php` — `submitForm()`

The `{formId}` path segment is accepted as a raw string and stored verbatim in the Signal payload as `form_id`. There is no format validation (UUID, alphanumeric, max length). An attacker can supply a multi-kilobyte `formId` in the URL to pollute Signal records. Low severity because it is bounded by server URL length limits and does not affect security logic.

**Remediation:** Add `->whereUuid('formId')` to the route definition, or validate `preg_match('/^[a-z0-9\-]{1,100}$/i', $formId)` in the controller.

---

## Confirmed Safe

- **Multi-tenancy (TeamScope):** Both `Website` and `WebsitePage` use `BelongsToTeam` + `TeamScope` global scope. All authenticated API endpoints resolve models via implicit route model binding which goes through that scope. User A cannot reach User B's websites. (H2 above is about rate abuse, not tenant escape.)
- **Public site enumeration:** `PublicSiteController` requires `status = 'published'` on the website and `status = 'published'` on each page before serving any content. Draft websites and draft pages are invisible. The pages listing endpoint also filters to published only.
- **MCP tool team isolation:** All 11 Website MCP tools use `app('mcp.team_id') ?? auth()->user()?->current_team_id` and abort with an error if no team is resolved. No tool operates without a bound team.
- **ZIP path traversal:** Page slugs are regex-validated to `[a-z0-9-]+` at write time; the ZIP builder uses them as filenames without additional sanitization, but the validated format makes traversal impossible.
- **Input validation coverage:** `WebsiteController` and `WebsitePageController` both call `$request->validate()` before dispatching actions. Actions receive explicit named parameters, not raw `$request->all()`. No mass-assignment risk from HTTP input.
- **GrapesJS metadata:** `<title>` and `<meta description>` are `htmlspecialchars()`-escaped in `toStandaloneHtml()`.
