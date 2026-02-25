<x-layouts.public
    title="Privacy Policy — FleetQ"
    description="Learn how FleetQ collects, uses, and protects your personal data. GDPR and CCPA compliant."
>
    <x-landing.nav />

    <article class="mx-auto max-w-4xl px-6 py-16 sm:py-24 lg:px-8">
        <div class="prose prose-gray max-w-none prose-headings:font-bold prose-h1:text-3xl prose-h1:sm:text-4xl prose-h2:text-xl prose-h2:sm:text-2xl prose-h2:mt-10 prose-h2:border-t prose-h2:border-gray-200 prose-h2:pt-8 prose-a:text-primary-600 prose-a:no-underline hover:prose-a:underline prose-table:text-sm prose-th:bg-gray-50 prose-th:px-4 prose-th:py-2 prose-td:px-4 prose-td:py-2">
            <p class="text-sm text-gray-500">Last updated: 22 February 2026</p>

            <h1>Privacy Policy</h1>

            <h2>1. Introduction</h2>
            <p>PriceX Ltd. ("we," "us," or "our") operates FleetQ (the "Service"). This Privacy Policy explains how we collect, use, disclose, and safeguard your personal data when you use our Service.</p>
            <p>We are committed to protecting your privacy in accordance with the General Data Protection Regulation (GDPR), the California Consumer Privacy Act (CCPA/CPRA), and other applicable data protection laws.</p>

            <h2>2. Data Controller</h2>
            <ul>
                <li><strong>Company:</strong> PriceX Ltd.</li>
                <li><strong>Address:</strong> Bulgaria, Plovdiv, 1 Petyofi street</li>
                <li><strong>Email:</strong> <a href="mailto:privacy@pricex.app">privacy@pricex.app</a></li>
                <li><strong>Data Protection Officer:</strong> Nikola Katsarov (<a href="mailto:nikola.katsarov@pricex.app">nikola.katsarov@pricex.app</a>)</li>
            </ul>

            <h2>3. Personal Data We Collect</h2>
            <h3>3.1 Data You Provide Directly</h3>
            <table>
                <thead>
                    <tr><th>Category</th><th>Data Elements</th><th>Purpose</th><th>Legal Basis</th></tr>
                </thead>
                <tbody>
                    <tr><td>Account Data</td><td>Name, email, password (hashed)</td><td>Account creation and authentication</td><td>Contract performance</td></tr>
                    <tr><td>Team Data</td><td>Team name, settings, member roles</td><td>Multi-user workspace management</td><td>Contract performance</td></tr>
                    <tr><td>AI Provider Credentials</td><td>API keys (encrypted)</td><td>BYOK AI processing</td><td>Contract performance</td></tr>
                    <tr><td>Billing Data</td><td>Billing name, address, payment method (via Stripe)</td><td>Subscription billing</td><td>Contract performance</td></tr>
                </tbody>
            </table>

            <h3>3.2 Data Collected Automatically</h3>
            <table>
                <thead>
                    <tr><th>Category</th><th>Data Elements</th><th>Purpose</th><th>Legal Basis</th></tr>
                </thead>
                <tbody>
                    <tr><td>Usage Data</td><td>Pages visited, features used, experiment runs</td><td>Service improvement and quota enforcement</td><td>Legitimate interest</td></tr>
                    <tr><td>Device Data</td><td>IP address, browser type, OS</td><td>Security and compatibility</td><td>Legitimate interest</td></tr>
                    <tr><td>Session Data</td><td>Session identifiers (Redis-backed)</td><td>Authentication</td><td>Contract performance</td></tr>
                    <tr><td>Analytics Data</td><td>Anonymized page views (Plausible Analytics)</td><td>Aggregated statistics</td><td>Legitimate interest</td></tr>
                </tbody>
            </table>

            <h2>4. How We Use Your Data</h2>
            <ol>
                <li><strong>Service Delivery:</strong> To provide, maintain, and improve the FleetQ platform</li>
                <li><strong>Account Management:</strong> To create and manage your account, team, and subscriptions</li>
                <li><strong>AI Processing:</strong> To execute AI agents, skills, and experiments using your configured LLM providers</li>
                <li><strong>Billing:</strong> To process subscription payments and generate invoices via Stripe</li>
                <li><strong>Security:</strong> To protect against unauthorized access, enforce rate limits, and maintain platform integrity</li>
                <li><strong>Analytics:</strong> To understand aggregated usage patterns via privacy-respecting Plausible Analytics</li>
                <li><strong>Legal Compliance:</strong> To comply with legal obligations including tax records and regulatory reporting</li>
                <li><strong>Communication:</strong> To send service-related notifications (usage alerts, weekly digests, approval requests)</li>
            </ol>

            <h2>5. Legal Bases for Processing (GDPR)</h2>
            <table>
                <thead>
                    <tr><th>Legal Basis</th><th>Processing Activities</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>Consent (Art. 6(1)(a))</strong></td><td>Marketing emails, optional analytics features</td></tr>
                    <tr><td><strong>Contract (Art. 6(1)(b))</strong></td><td>Account creation, service delivery, AI agent execution, payment processing</td></tr>
                    <tr><td><strong>Legal Obligation (Art. 6(1)(c))</strong></td><td>Tax records, regulatory reporting, audit logs</td></tr>
                    <tr><td><strong>Legitimate Interest (Art. 6(1)(f))</strong></td><td>Security monitoring, fraud prevention, service improvement, anonymized analytics</td></tr>
                </tbody>
            </table>

            <h2>6. Data Sharing and Disclosure</h2>
            <table>
                <thead>
                    <tr><th>Recipient</th><th>Purpose</th><th>Safeguards</th></tr>
                </thead>
                <tbody>
                    <tr><td>Stripe (USA)</td><td>Payment processing</td><td>PCI DSS Level 1, DPA, SCCs</td></tr>
                    <tr><td>Anthropic (USA)</td><td>AI model inference (Claude)</td><td>DPA, SCCs</td></tr>
                    <tr><td>OpenAI (USA)</td><td>AI model inference (GPT-4o)</td><td>DPA, SCCs</td></tr>
                    <tr><td>Google (USA)</td><td>AI model inference (Gemini)</td><td>DPA, SCCs</td></tr>
                    <tr><td>Plausible Analytics (EU)</td><td>Privacy-respecting analytics</td><td>EU-hosted, no personal data</td></tr>
                </tbody>
            </table>
            <p>We do <strong>NOT</strong> sell your personal data.</p>
            <p><strong>Important:</strong> When you configure AI providers via BYOK (Bring Your Own Key), your prompts and data are sent directly to the LLM provider you choose. The data processing relationship for AI inference is between you and your chosen provider.</p>

            <h2>7. International Data Transfers</h2>
            <p>We transfer personal data outside the EEA to the United States (Stripe, Anthropic, OpenAI, Google), protected by Standard Contractual Clauses (SCCs) and supplementary measures.</p>

            <h2>8. Data Retention</h2>
            <table>
                <thead>
                    <tr><th>Data Category</th><th>Retention Period</th><th>Basis</th></tr>
                </thead>
                <tbody>
                    <tr><td>Account Data</td><td>Duration of account + 6 months</td><td>Contract + legal obligations</td></tr>
                    <tr><td>AI Execution Logs</td><td>90 days (configurable per plan)</td><td>Legitimate interest</td></tr>
                    <tr><td>Audit Trail</td><td>30-365 days (per subscription plan)</td><td>Legal obligation</td></tr>
                    <tr><td>Billing Data</td><td>7 years</td><td>Tax/legal requirements</td></tr>
                    <tr><td>Usage Metrics</td><td>12 months aggregated, 30 days raw</td><td>Legitimate interest</td></tr>
                </tbody>
            </table>

            <h2>9. Your Rights</h2>
            <h3>9.1 GDPR Rights (EU/EEA Residents)</h3>
            <ul>
                <li><strong>Access</strong> your personal data (Art. 15)</li>
                <li><strong>Rectify</strong> inaccurate data (Art. 16)</li>
                <li><strong>Erase</strong> your data ("right to be forgotten") (Art. 17)</li>
                <li><strong>Restrict</strong> processing (Art. 18)</li>
                <li><strong>Data portability</strong> — receive your data in a machine-readable format (Art. 20)</li>
                <li><strong>Object</strong> to processing (Art. 21)</li>
                <li><strong>Withdraw consent</strong> at any time (Art. 7(3))</li>
                <li><strong>Lodge a complaint</strong> with the Bulgarian CPDP or your local supervisory authority</li>
            </ul>

            <h3>9.2 CCPA/CPRA Rights (California Residents)</h3>
            <ul>
                <li><strong>Know</strong> what personal information we collect</li>
                <li><strong>Delete</strong> your personal information</li>
                <li><strong>Correct</strong> inaccurate personal information</li>
                <li><strong>Opt-out</strong> of the sale or sharing of personal information</li>
                <li><strong>Non-discrimination</strong> for exercising your rights</li>
            </ul>
            <p>To exercise your rights, contact us at <a href="mailto:privacy@pricex.app">privacy@pricex.app</a>. We respond within 30 days (GDPR) or 45 days (CCPA).</p>

            <h2>10. Data Security</h2>
            <ul>
                <li>Encryption of data at rest and in transit (TLS 1.2+)</li>
                <li>Encrypted storage of sensitive fields (2FA secrets, API keys)</li>
                <li>Password hashing using bcrypt</li>
                <li>CSRF protection on all forms</li>
                <li>Security headers (X-Content-Type-Options, X-Frame-Options, HSTS)</li>
                <li>Role-based access controls (owner, admin, member, viewer)</li>
                <li>Rate limiting on API endpoints and AI calls</li>
                <li>Audit trail of all sensitive operations</li>
            </ul>

            <h2>11. Automated Decision-Making</h2>
            <p>Our Service uses AI/LLM processing for experiment execution, agent tasks, and skill execution. These are configured and initiated by users, do not produce decisions with legal effects on data subjects, and include human-in-the-loop approval workflows for high-risk operations.</p>

            <h2>12. Children's Privacy</h2>
            <p>Our Service is not directed to individuals under 16 years of age. We do not knowingly collect personal data from children.</p>

            <h2>13. Changes to This Policy</h2>
            <p>We will notify you of material changes via email at least 30 days before they take effect.</p>

            <h2>14. Contact Us</h2>
            <ul>
                <li><strong>Email:</strong> <a href="mailto:privacy@pricex.app">privacy@pricex.app</a></li>
                <li><strong>Address:</strong> PriceX Ltd., 1 Petyofi street, Plovdiv, Bulgaria</li>
                <li><strong>DPO:</strong> Nikola Katsarov — <a href="mailto:nikola.katsarov@pricex.app">nikola.katsarov@pricex.app</a></li>
                <li><strong>Supervisory Authority:</strong> <a href="https://www.cpdp.bg" rel="noopener noreferrer" target="_blank">Commission for Personal Data Protection (CPDP), Bulgaria</a></li>
            </ul>
        </div>
    </article>

    <x-landing.footer />
</x-layouts.public>
