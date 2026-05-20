<?php

/**
 * Allowlist of Livewire write methods that intentionally don't call Gate::authorize.
 *
 * Format: [FullyQualifiedClass::method => 'reason']
 *
 * As of the 2026-05-19 livewire-authorize sweep, every mutating Livewire
 * method in base/app/Livewire/ AND cloud/Livewire/ carries an in-method
 * authorization gate — except the one genuine pre-auth exemption below.
 * Livewire component-update requests bypass route middleware, so the
 * in-method gate is the real protection.
 *
 * Do NOT add entries here to silence the test. A new ungated write method must
 * gain a real `Gate::authorize(...)` / `$this->authorize(...)` call. The only
 * legitimate allowlist use is a method that genuinely runs before any user
 * exists (like first-run setup) or a UI-only method the heuristics mis-detect.
 */

return [
    // First-run install wizard: creates the very first user + team. Runs with
    // no authenticated user, so there is nothing to authorize against. The
    // page's mount() redirects to login once any user exists, making the
    // method unreachable thereafter.
    'App\\Livewire\\Setup\\SetupPage::createAccount' => 'first-run install wizard — runs pre-auth; page is unreachable once any user exists',
];
