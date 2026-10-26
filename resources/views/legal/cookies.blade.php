<x-layouts.public
    title="Cookie Policy — FleetQ"
    description="Learn about the cookies and similar technologies used by FleetQ."
>
    <x-landing.nav />

    <article class="mx-auto max-w-4xl px-6 py-16 sm:py-24 lg:px-8">
        <div class="prose prose-gray max-w-none prose-headings:font-bold prose-h1:text-3xl prose-h1:sm:text-4xl prose-h2:text-xl prose-h2:sm:text-2xl prose-h2:mt-10 prose-h2:border-t prose-h2:border-gray-200 prose-h2:pt-8 prose-a:text-primary-600 prose-a:no-underline hover:prose-a:underline prose-table:text-sm prose-th:bg-gray-50 prose-th:px-4 prose-th:py-2 prose-td:px-4 prose-td:py-2">
            <p class="text-sm text-gray-500">Last updated: 22 February 2026</p>

            <h1>Cookie Policy</h1>

            <h2>1. What Are Cookies</h2>
            <p>Cookies are small text files stored on your device when you visit a website. They serve various purposes including remembering your preferences and maintaining your session.</p>

            <h2>2. How We Use Cookies</h2>
            <p>PriceX Ltd. uses cookies and similar technologies (local storage, session storage) on the FleetQ platform.</p>

            <h2>3. Cookie Categories</h2>

            <h3>3.1 Strictly Necessary Cookies</h3>
            <p>These cookies are essential for the website to function and cannot be disabled.</p>
            <table>
                <thead>
                    <tr><th>Cookie Name</th><th>Purpose</th><th>Duration</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>fleetq_session</code></td><td>Session management and authentication</td><td>2 hours (session)</td></tr>
                    <tr><td><code>XSRF-TOKEN</code></td><td>CSRF protection for form submissions</td><td>2 hours (session)</td></tr>
                    <tr><td><code>remember_web_*</code></td><td>"Remember me" persistent login</td><td>30 days</td></tr>
                </tbody>
            </table>

            <h3>3.2 Analytics Cookies</h3>
            <p>We use <strong>Plausible Analytics</strong>, a privacy-respecting analytics service that does <strong>not</strong> use cookies. Plausible:</p>
            <ul>
                <li>Does not set any cookies</li>
                <li>Does not collect personal data</li>
                <li>Does not track users across sites</li>
                <li>Is hosted in the EU</li>
                <li>Complies with GDPR, CCPA, and PECR without requiring consent</li>
            </ul>
            @if(config('services.google_analytics.id'))
                <p>This site also uses <strong>Google Analytics 4</strong> (Google Ireland Ltd.), but only after you click <strong>Accept</strong> in the cookie banner. Until then, and if you click <strong>Reject</strong>, the Google Analytics script is not loaded, no request goes to Google, and no analytics cookies are set. If you accept, Google Analytics sets the cookies <code>_ga</code> and <code>_ga_&lt;ID&gt;</code> (up to 13 months) to count visits; Google's advertising features stay off.</p>
                <p>We keep your choice, with a version and a date, in your browser's local storage for 12 months. You can withdraw at any time with the &ldquo;Cookie settings&rdquo; link: choosing Reject there disables Google Analytics and deletes the <code>_ga</code> cookies.</p>
            @else
                <p><strong>No analytics cookies are set by FleetQ.</strong></p>
            @endif

            <h3>3.3 Functionality Storage</h3>
            <table>
                <thead>
                    <tr><th>Item</th><th>Type</th><th>Purpose</th><th>Duration</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>theme</code></td><td>Local Storage</td><td>Theme preference (light/dark)</td><td>Persistent</td></tr>
                    <tr><td><code>sidebar_collapsed</code></td><td>Local Storage</td><td>Sidebar UI state</td><td>Persistent</td></tr>
                </tbody>
            </table>

            <h3>3.4 Advertising / Targeting Cookies</h3>
            <p>FleetQ does <strong>not</strong> use any advertising or targeting cookies.</p>

            <h2>4. Third-Party Cookies</h2>
            <table>
                <thead>
                    <tr><th>Service</th><th>Purpose</th><th>Sets Cookies?</th><th>Privacy Policy</th></tr>
                </thead>
                <tbody>
                    <tr><td>Plausible Analytics</td><td>Usage analytics</td><td>No (cookieless)</td><td><a href="https://plausible.io/privacy" rel="noopener noreferrer" target="_blank">plausible.io/privacy</a></td></tr>
                    @if(config('services.google_analytics.id'))
                        <tr><td>Google Analytics 4</td><td>Usage analytics (only after you accept)</td><td>Yes, after Accept: <code>_ga</code>, <code>_ga_&lt;ID&gt;</code></td><td><a href="https://policies.google.com/privacy" rel="noopener noreferrer" target="_blank">policies.google.com/privacy</a></td></tr>
                    @endif
                    <tr><td>Stripe</td><td>Payment processing</td><td>Yes (on payment pages only)</td><td><a href="https://stripe.com/privacy" rel="noopener noreferrer" target="_blank">stripe.com/privacy</a></td></tr>
                </tbody>
            </table>

            <h2>5. Managing Your Cookies</h2>
            @if(config('services.google_analytics.id'))
                <p>Besides strictly necessary cookies and cookieless analytics (Plausible), we use Google Analytics cookies only with your consent. The banner has equal Accept and Reject buttons, and the &ldquo;Cookie settings&rdquo; link lets you change your choice at any time.</p>
            @else
                <p>Since FleetQ only uses strictly necessary cookies (session, CSRF) and cookieless analytics (Plausible), no cookie consent banner is required under ePrivacy Directive Article 5(3).</p>
            @endif
            <p>You can control cookies through your browser settings:</p>
            <ul>
                <li><strong>Chrome:</strong> Settings &gt; Privacy and Security &gt; Cookies</li>
                <li><strong>Firefox:</strong> Settings &gt; Privacy &amp; Security &gt; Cookies and Site Data</li>
                <li><strong>Safari:</strong> Preferences &gt; Privacy &gt; Manage Website Data</li>
                <li><strong>Edge:</strong> Settings &gt; Privacy, search, and services &gt; Cookies</li>
            </ul>
            <p><em>Note: Disabling session cookies will prevent you from logging into FleetQ.</em></p>

            <h2>6. Global Privacy Control</h2>
            <p>We honor the Global Privacy Control (GPC) and Do Not Track (DNT) browser signals.</p>

            <h2>7. Updates to This Policy</h2>
            <p>We will update this Cookie Policy when we add or remove cookies or tracking technologies.</p>

            <h2>8. Contact</h2>
            <ul>
                <li><strong>Email:</strong> <a href="mailto:privacy@pricex.app">privacy@pricex.app</a></li>
                <li><strong>Address:</strong> PriceX Ltd., 1 Petyofi street, Plovdiv, Bulgaria</li>
            </ul>
        </div>
    </article>

    <x-landing.footer />
</x-layouts.public>
