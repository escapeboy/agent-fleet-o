# Privacy Policy

**Last updated:** 22 February 2026

## 1. Introduction

PriceX Ltd. ("we," "us," or "our") operates FleetQ (the "Service"). This Privacy Policy explains how we collect, use, disclose, and safeguard your personal data when you use our Service.

We are committed to protecting your privacy in accordance with the General Data Protection Regulation (GDPR), the California Consumer Privacy Act (CCPA/CPRA), and other applicable data protection laws.

## 2. Data Controller

**Company:** PriceX Ltd.
**Address:** Bulgaria, Plovdiv, 1 Petyofi street
**Email:** privacy@pricex.app
**Data Protection Officer:** Nikola Katsarov (nikola.katsarov@pricex.app)

## 3. Personal Data We Collect

### 3.1 Data You Provide Directly

| Category | Data Elements | Purpose | Legal Basis |
|----------|--------------|---------|-------------|
| Account Data | Name, email address, password (hashed) | Account creation and authentication | Contract performance |
| Team Data | Team name, team settings, member roles | Multi-user workspace management | Contract performance |
| AI Provider Credentials | API keys for Anthropic, OpenAI, Google (encrypted) | BYOK (Bring Your Own Key) AI processing | Contract performance |
| Billing Data | Billing name, address, payment method (via Stripe) | Subscription billing and invoicing | Contract performance |
| Communication Data | Support requests, feedback | Customer support | Legitimate interest |

### 3.2 Data Collected Automatically

| Category | Data Elements | Purpose | Legal Basis |
|----------|--------------|---------|-------------|
| Usage Data | Pages visited, features used, experiment runs, agent executions | Service improvement and quota enforcement | Legitimate interest |
| Device Data | IP address, browser type, operating system | Security and compatibility | Legitimate interest |
| Session Data | Session identifiers (Redis-backed) | Authentication and session management | Contract performance |
| Analytics Data | Anonymized page views (via Plausible Analytics) | Aggregated usage statistics | Legitimate interest |

### 3.3 Data from Third Parties

| Source | Data Elements | Purpose |
|--------|--------------|---------|
| Stripe | Subscription status, payment confirmation | Billing management |
| LLM Providers (Anthropic, OpenAI, Google) | AI response data, token usage | AI-powered agent execution |

## 4. How We Use Your Data

We process your personal data for the following purposes:

1. **Service Delivery:** To provide, maintain, and improve the FleetQ platform
2. **Account Management:** To create and manage your account, team, and subscriptions
3. **AI Processing:** To execute AI agents, skills, and experiments using your configured LLM providers
4. **Billing:** To process subscription payments and generate invoices via Stripe
5. **Security:** To protect against unauthorized access, enforce rate limits, and maintain platform integrity
6. **Analytics:** To understand aggregated usage patterns and improve user experience (via privacy-respecting Plausible Analytics)
7. **Legal Compliance:** To comply with legal obligations including tax records and regulatory reporting
8. **Communication:** To send service-related notifications (usage alerts, weekly digests, approval requests)

## 5. Legal Bases for Processing (GDPR)

| Legal Basis | Processing Activities |
|-------------|----------------------|
| **Consent (Art. 6(1)(a))** | Marketing emails, optional analytics features |
| **Contract (Art. 6(1)(b))** | Account creation, service delivery, AI agent execution, payment processing |
| **Legal Obligation (Art. 6(1)(c))** | Tax records, regulatory reporting, audit logs |
| **Legitimate Interest (Art. 6(1)(f))** | Security monitoring, fraud prevention, service improvement, anonymized analytics |

## 6. Data Sharing and Disclosure

We share personal data with the following categories of recipients:

| Recipient | Purpose | Safeguards |
|-----------|---------|------------|
| Stripe (USA) | Payment processing | PCI DSS Level 1 certified, DPA, SCCs |
| Anthropic (USA) | AI model inference (when using Anthropic as LLM provider) | DPA, SCCs |
| OpenAI (USA) | AI model inference (when using OpenAI as LLM provider) | DPA, SCCs |
| Google (USA) | AI model inference (when using Google as LLM provider) | DPA, SCCs |
| Plausible Analytics (EU) | Privacy-respecting website analytics | EU-hosted, no personal data transferred |
| Hosting Provider | Infrastructure and data storage | DPA, SOC 2 certified |

We do **NOT** sell your personal data.

**Important:** When you configure AI providers via BYOK (Bring Your Own Key), your prompts, experiment data, and agent inputs/outputs are sent directly to the LLM provider you choose. The data processing relationship for AI inference is between you and your chosen provider.

## 7. International Data Transfers

We transfer personal data outside the European Economic Area (EEA) to:
- **United States (Stripe, Anthropic, OpenAI, Google):** Protected by Standard Contractual Clauses (SCCs) and supplementary measures

Transfer Impact Assessments are conducted for each destination country. We only engage processors that provide adequate safeguards in compliance with GDPR Chapter V.

## 8. Data Retention

| Data Category | Retention Period | Basis |
|--------------|-----------------|-------|
| Account Data | Duration of account + 6 months | Contract + legal obligations |
| Team & Workspace Data | Duration of team membership + 6 months | Contract |
| AI Execution Logs | 90 days (configurable per plan) | Legitimate interest |
| Audit Trail | 30-365 days (per subscription plan) | Legal obligation + legitimate interest |
| Billing Data | 7 years | Tax/legal requirements (Bulgarian law) |
| Usage Metrics | 12 months (aggregated), 30 days (raw) | Legitimate interest |
| Session Data | Until logout or session expiry | Contract |
| Analytics Data (Plausible) | Anonymized, no personal data retained | N/A |

Data is securely deleted or anonymized after the retention period expires. The `audit:cleanup` command enforces plan-specific retention automatically.

## 9. Your Rights

### 9.1 GDPR Rights (EU/EEA Residents)

You have the right to:
- **Access** your personal data (Art. 15)
- **Rectify** inaccurate data (Art. 16)
- **Erase** your data ("right to be forgotten") (Art. 17)
- **Restrict** processing (Art. 18)
- **Data portability** — receive your data in a machine-readable format (Art. 20)
- **Object** to processing, including profiling (Art. 21)
- **Withdraw consent** at any time (Art. 7(3))
- **Lodge a complaint** with the Bulgarian Commission for Personal Data Protection (CPDP) or your local supervisory authority

### 9.2 CCPA/CPRA Rights (California Residents)

You have the right to:
- **Know** what personal information we collect, use, and disclose
- **Delete** your personal information
- **Correct** inaccurate personal information
- **Opt-out** of the sale or sharing of personal information (we do not sell data)
- **Limit** use of sensitive personal information
- **Non-discrimination** for exercising your rights

To exercise your rights, contact us at privacy@pricex.app or reach out to our DPO at nikola.katsarov@pricex.app.

We respond to all requests within 30 days (GDPR) or 45 days (CCPA).

## 10. Data Security

We implement appropriate technical and organizational measures including:
- Encryption of data at rest and in transit (TLS 1.2+)
- Encrypted storage of sensitive fields (2FA secrets, API keys) using Laravel's `encrypted` cast
- Password hashing using bcrypt
- CSRF protection on all forms
- Security headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, HSTS)
- Role-based access controls (owner, admin, member, viewer)
- Rate limiting on API endpoints and AI calls
- Circuit breaker pattern for external provider calls
- Redis-backed session management with secure flags
- Audit trail of all sensitive operations

## 11. Automated Decision-Making

Our Service uses AI/LLM processing for experiment execution, agent tasks, and skill execution. These automated processes:
- Are configured and initiated by users, not applied automatically to individuals
- Do not produce decisions with legal or similarly significant effects on data subjects
- Can be reviewed, paused, or stopped by authorized team members at any time
- Include human-in-the-loop approval workflows for high-risk operations

## 12. Children's Privacy

Our Service is not directed to individuals under 16 years of age. We do not knowingly collect personal data from children. If we become aware of such collection, we will delete the data promptly.

## 13. Changes to This Policy

We will notify you of material changes via email notification at least 30 days before they take effect. Continued use of the Service after changes constitutes acceptance.

## 14. Contact Us

For privacy-related inquiries:
- **Email:** privacy@pricex.app
- **Address:** PriceX Ltd., 1 Petyofi street, Plovdiv, Bulgaria
- **DPO:** Nikola Katsarov — nikola.katsarov@pricex.app
- **Supervisory Authority:** Commission for Personal Data Protection (CPDP), Bulgaria — https://www.cpdp.bg

---

*This Privacy Policy was generated as part of a compliance audit. It should be reviewed by a qualified legal professional before publication.*
