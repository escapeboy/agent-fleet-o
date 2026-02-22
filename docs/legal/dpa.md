# Data Processing Agreement (DPA)

**Effective Date:** 22 February 2026

This Data Processing Agreement ("DPA") forms part of the agreement between:

**Data Controller:** The Customer ("Controller")

**Data Processor:** PriceX Ltd. ("Processor")
**Address:** Bulgaria, Plovdiv, 1 Petyofi street

(collectively "the Parties")

## 1. Definitions

- **Personal Data:** Any information relating to an identified or identifiable natural person
- **Processing:** Any operation performed on Personal Data (collection, storage, use, disclosure, erasure)
- **Data Subject:** The individual whose Personal Data is processed
- **Sub-processor:** A third party engaged by the Processor to process Personal Data
- **Supervisory Authority:** The competent data protection authority
- **Data Protection Laws:** GDPR, applicable EU Member State laws, and any other applicable privacy legislation

## 2. Subject Matter and Duration

### 2.1 Subject Matter
The Processor processes Personal Data on behalf of the Controller as necessary to provide the Agent Fleet platform services ("Services").

### 2.2 Duration
This DPA remains in effect for the duration of the main agreement plus the period required to delete or return all Personal Data.

### 2.3 Nature and Purpose of Processing

| Aspect | Description |
|--------|-------------|
| **Purpose** | Providing the Agent Fleet SaaS platform including AI agent execution, experiment pipeline, workflow automation, and team collaboration |
| **Nature** | Collection, storage, processing by AI models (via Controller's API keys), transmission, deletion |
| **Categories of Data Subjects** | Controller's team members, end users of Controller's AI-powered workflows |
| **Categories of Personal Data** | Name, email address, IP address, session data, AI prompt/response data, usage metrics |
| **Sensitive Data** | None by default. If Controller's AI workflows process sensitive data, Controller is responsible for ensuring appropriate safeguards. |

## 3. Obligations of the Processor

The Processor shall:

3.1. Process Personal Data only on documented instructions from the Controller, unless required by EU or Member State law

3.2. Ensure that persons authorized to process Personal Data have committed to confidentiality

3.3. Implement appropriate technical and organizational security measures (see Annex 1)

3.4. Not engage another processor (sub-processor) without prior specific or general written authorization of the Controller

3.5. Assist the Controller in fulfilling data subject rights requests

3.6. Assist the Controller in ensuring compliance with Articles 32-36 GDPR (security, breach notification, DPIA)

3.7. At the Controller's choice, delete or return all Personal Data after the end of services, and delete existing copies unless EU law requires storage

3.8. Make available all information necessary to demonstrate compliance and allow for audits

3.9. Immediately inform the Controller if, in the Processor's opinion, an instruction infringes GDPR

## 4. Sub-processors

### 4.1 Current Sub-processors

| Sub-processor | Purpose | Location | Safeguards |
|--------------|---------|----------|------------|
| Anthropic | AI model inference (Claude) | USA | SCCs, DPA |
| OpenAI | AI model inference (GPT-4o) | USA | SCCs, DPA |
| Google | AI model inference (Gemini) | USA | SCCs, DPA |
| Stripe | Payment processing | USA | PCI DSS Level 1, SCCs, DPA |
| Plausible Analytics | Cookieless website analytics | EU (Germany) | EU-hosted, no personal data |
| Hosting Provider | Infrastructure and data storage | EU | SOC 2, DPA |

**Note on BYOK (Bring Your Own Key):** When the Controller provides their own API keys for AI providers, the Controller enters into a direct data processing relationship with those providers. The Processor acts as a conduit and does not independently determine the purposes of AI processing.

### 4.2 Notification of Changes
The Processor shall notify the Controller at least 30 days before adding or replacing a sub-processor, giving the Controller the opportunity to object.

### 4.3 Sub-processor Obligations
The Processor shall impose the same data protection obligations on each sub-processor by way of a contract.

## 5. International Transfers

5.1. The Processor shall not transfer Personal Data outside the EEA without prior written consent of the Controller and appropriate safeguards (SCCs, adequacy decision, or other GDPR-compliant mechanism).

5.2. Where Standard Contractual Clauses apply, they are incorporated by reference as Annex 2.

## 6. Security Measures

The Processor implements the technical and organizational measures described in **Annex 1**, including:
- Encryption of Personal Data at rest and in transit
- Encrypted storage of sensitive credentials (API keys, 2FA secrets)
- Access controls and role-based authentication (owner, admin, member, viewer)
- Rate limiting and circuit breaker protection
- Audit trail of all sensitive operations
- Automated data retention and cleanup
- Redis-backed session management with secure configuration

## 7. Data Breach Notification

7.1. The Processor shall notify the Controller without undue delay (and no later than 48 hours) after becoming aware of a Personal Data breach.

7.2. The notification shall include:
- Nature of the breach
- Categories and approximate number of data subjects affected
- Categories and approximate number of records affected
- Likely consequences
- Measures taken or proposed to address the breach

7.3. The Processor shall cooperate with the Controller in investigating and remediating the breach.

## 8. Data Subject Rights

8.1. The Processor shall assist the Controller in responding to data subject requests (access, rectification, erasure, restriction, portability, objection).

8.2. The Processor shall promptly notify the Controller if it receives a request directly from a data subject.

## 9. Audit Rights

9.1. The Processor shall make available all information necessary to demonstrate compliance with this DPA.

9.2. The Controller (or an appointed auditor) may conduct audits with 30 days' written notice, during business hours, no more than once per year.

9.3. The Processor may satisfy audit requests through provision of SOC 2 Type II report or equivalent certification.

## 10. Liability

Each Party's liability under this DPA is subject to the limitations of liability set forth in the main agreement.

## 11. Termination

11.1. This DPA terminates automatically when the main agreement terminates.

11.2. Upon termination, the Processor shall (at Controller's election) return or securely delete all Personal Data within 30 days.

---

## Annex 1: Technical and Organizational Security Measures

### A. Encryption
- Data at rest: AES-256 (database encryption), Laravel `encrypted` cast for sensitive fields
- Data in transit: TLS 1.2+ enforced, HSTS in production
- Key management: Application-level encryption keys, provider API keys stored encrypted

### B. Access Control
- Authentication: Laravel Fortify with 2FA support, bcrypt password hashing
- Authorization: Role-based (owner/admin/member/viewer) with Laravel Gates
- API tokens: Laravel Sanctum with 30-day expiry, team-scoped
- Access reviews: Audit log of all access and changes

### C. Infrastructure Security
- Network: Security headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy)
- Containers: Docker with Alpine-based images, non-root processes
- Monitoring: Laravel Horizon for queue monitoring, health checks every 5 minutes

### D. Data Management
- Backups: PostgreSQL backups with encrypted storage
- Retention: Configurable per plan (30-365 days for audit data), automated cleanup
- Disposal: Soft deletes with scheduled hard purges after retention period

### E. Incident Response
- Response time: < 48 hours for breach notification
- Monitoring: Automated alerts on budget thresholds, health check failures, critical experiment transitions
- Recovery: Circuit breaker pattern for provider failures, automated pause on budget exceeded

### F. Personnel
- Confidentiality: All personnel bound by confidentiality obligations
- Training: Data protection awareness for all team members with access

---

## Annex 2: Standard Contractual Clauses

Where applicable, the EU Commission's Standard Contractual Clauses (Module Two: Controller to Processor) as adopted by Commission Implementing Decision (EU) 2021/914 are incorporated by reference.

---

**Signatures:**

**Controller:**
Name: ___________________
Title: ___________________
Date: ___________________
Signature: ___________________

**Processor:** PriceX Ltd.
Name: ___________________
Title: ___________________
Date: ___________________
Signature: ___________________

---

*This Data Processing Agreement was generated as part of a compliance audit. It should be reviewed by a qualified legal professional before execution.*
