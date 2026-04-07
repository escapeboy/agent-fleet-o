---
id: 2026-04-07-002
title: Website Generation Completion — Navigation + Forms
status: in-progress
type: feat
---

# Problem

`GenerateWebsiteFromPromptAction` stops after creating pages with static HTML.
- No cross-page navigation (broken links)
- No functional contact forms
- AI has all page slugs in the same JSON response but doesn't use them for links

# Root Causes

1. System prompt says "Pages should be complete and standalone" → AI treats each page independently
2. Website slug is computed AFTER the LLM call, so form actions can't reference it
3. `<form>` elements are stripped by HtmlSanitizer (not in allowed list)

# Solution: A + B Hybrid

## Phase 1 — Improved LLM Prompt
- Pre-compute `$websiteSlug = Str::slug($name)` before the LLM call
- Pass slug to system prompt so AI can generate real form `action` URIs
- Instruct AI to include `<nav>` with links to all generated pages
- AI generates all pages in one call, so all slugs are known — require cross-linking

## Phase 2 — EnhanceWebsiteNavigationAction (deterministic post-processor)
After all pages are created:
1. Build navigation HTML from all actual page slugs/titles
2. For each page: inject/replace `<nav>` block
3. For pages with form intent: inject working contact form pointing to `/api/public/{slug}/forms/{uuid}/submit`
4. Update pages directly (skip second sanitizer pass)

## HtmlSanitizer change
Add form elements to allowed list: `form`, `input`, `textarea`, `button`, `label`, `select`, `option`

# Files

| Action | File |
|--------|------|
| Modify | `app/Domain/Website/Services/HtmlSanitizer.php` |
| Modify | `app/Domain/Website/Actions/GenerateWebsiteFromPromptAction.php` |
| Create | `app/Domain/Website/Actions/EnhanceWebsiteNavigationAction.php` |
| Create | `tests/Feature/Website/GenerateWebsiteTest.php` |
| Create | `tests/Feature/Website/EnhanceWebsiteNavigationTest.php` |
