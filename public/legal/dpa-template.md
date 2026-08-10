# Data Processing Agreement (DPA)

This Data Processing Agreement ("DPA") forms part of the Terms of Service between **YCookies ("Data Processor")** and **You ("Data Controller")**.

## 1. Scope and Applicability
This DPA applies to the processing of personal data by YCookies on behalf of the Data Controller for the provision of the cookie consent management and proxy services.

## 2. Roles of the Parties
Under GDPR Article 28, the customer acts as the Data Controller (deciding why and how personal data is processed), and YCookies acts as the Data Processor (processing data strictly on behalf of the Controller).

## 3. Nature and Purpose of Processing
- **Subject Matter:** The processing of website visitor IP hashes and consent choices.
- **Duration:** Personal data is processed for the duration of the active subscription and retained per the Controller's configured retention policy (up to 365 days).
- **Nature and Purpose:** To generate and record verifiable cookie consent logs to fulfill legal obligations under GDPR, ePrivacy Directive, and relevant local privacy laws.
- **Types of Personal Data:** Anonymized/Hashed IP Addresses, User Agent Strings, Consent Choices (Granted/Denied categories), Time/Date of consent.
- **Categories of Data Subjects:** End-users visually interacting with the Data Controller’s website via the YCookies proxy or script widget.

## 4. Obligations of the Data Processor (YCookies)
The Processor agrees to:
a. Process personal data only on documented instructions from the Controller.
b. Ensure that persons authorized to process the personal data have committed themselves to confidentiality.
c. Take all measures required pursuant to Article 32 regarding the security of processing.
d. Assist the Controller in fulfilling obligations to respond to requests for exercising the data subject's rights (e.g., data erasure).
e. Delete or return all personal data to the Controller after the end of the provision of services.

## 5. Security Measures
YCookies employs industry-standard security measures including:
- In-transit encryption (TLS 1.3).
- Strict access control and HMAC signature verification between proxy and control plane.
- One-way hashing of IP addresses (Salting via SHA-256).

## 6. Subprocessors
The Data Controller generally authorizes the engagement of subprocessors (e.g., Stripe for billing, Hetzner for infrastructure hosting) provided YCookies ensures equivalent data protection obligations are passed down to them.

---
**Version:** 1.0.0
**Last Updated:** March 2026
