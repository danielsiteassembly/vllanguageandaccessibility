# SOC 2 Type II Report
**Organization:** Visible Light, Inc.  
**System Name:** Visible Light AI Platform (Supercluster, Luna AI Copilot, VL Hub)  
**Report Period:** [Start Date] – [End Date]  
**Prepared By:** [Auditor Firm Name]  
**Issued To:** Visible Light, Inc.  
**Report Type:** SOC 2 Type II (Trust Services Criteria: Security, Availability, Confidentiality)

---

## 1. Independent Service Auditor’s Report
- **Opinion:** [Unqualified / Qualified]
- **Auditor Firm:** [Name, Location]
- **Engagement Scope:** Evaluation of design and operating effectiveness of controls
- **Criteria:** AICPA Trust Services Criteria (TSP Section 100)
- **Period Covered:** [Date Range]
- **Auditor’s Conclusion:**  
  > [Example] In our opinion, the controls were suitably designed and operated effectively throughout the period to provide reasonable assurance that the service commitments and system requirements were achieved.

---

## 2. Management Assertion
Visible Light management asserts that:
1. The system description is fairly presented.
2. The controls were suitably designed.
3. The controls operated effectively throughout the report period.

**Signed By:**  
- Daniel Devereux, CEO  
- [CISO / CTO Name], Chief Information Security Officer  
- [Compliance Officer Name], Compliance Lead  
**Date:** [MM/DD/YYYY]

---

## 3. System Description (Section 3 Narrative)

### 3.1 Company Overview
Visible Light provides an AI-powered CloudOps / WebOps platform centralizing client web infrastructure, cloud services, and data intelligence.

### 3.2 In-Scope Services
- **Supercluster Console:** CloudOps management & automation layer  
- **Luna AI Copilot:** AI-driven chat assistant & reporting engine  
- **Visible Light Hub (VL Hub):** Client data ingestion, harmonization & license management  
- **Voyager Site Explorer Plugin:** WordPress data connector  

### 3.3 System Components
- **Infrastructure:** AWS EC2, S3, RDS (PostgreSQL), Cloudflare, Auth0 (Identity)  
- **Software:** Microservices (Node.js, PHP, Python FastAPI), React front ends, WordPress plugin endpoints  
- **Data Stores:** PostgreSQL, ClickHouse (analytics), Redis (cache), Kafka (events)  
- **APIs:** vLite Core API (v1), Luna REST Endpoints, Supercluster GraphQL Interface  

### 3.4 Boundaries & Subservice Organizations
| Vendor | Service | Type | Compliance Evidence |
|--------|----------|------|---------------------|
| AWS | Hosting, storage | Subservice | SOC2 Type II, ISO 27001 |
| Cloudflare | CDN, WAF, DDoS | Subservice | SOC2 Type II |
| Auth0 | Identity, MFA | Subservice | SOC2 Type II |
| OpenAI | AI API processing | Subservice | SOC2 attestation |
| Mailchimp | Email delivery | Subservice | SOC2 Type II |

### 3.5 Data Flow Overview
