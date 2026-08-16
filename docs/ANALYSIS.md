# Digital Document Signing — Technical Analysis

**SignDesk** · prepared August 2026

This document answers the five questions set with the assignment. It describes
the system that was actually built and runs, not a proposal — every claim about
signature levels, timestamps and tamper detection below is reproduced by the
verification steps in [`../README.md`](../README.md).

---

## 1. Tech stack

| Layer | Choice |
|---|---|
| Admin & signer UI | React 19 · TypeScript · Vite 8 · Tailwind 4 · React Router 7 |
| PDF rendering (browser) | pdf.js 6 (`pdfjs-dist`) |
| API | Laravel 13 · PHP 8.3 · Sanctum |
| Database | PostgreSQL 16 |
| Queue & cache | Redis 7 (`predis`) |
| Object storage | S3 — MinIO in development |
| Email | SMTP — Mailpit in development, SES/Postmark in production |
| **PAdES sealing service** | **Python 3.12 · FastAPI · pyHanko 0.36** |
| PDF composition | pypdf · reportlab · Pillow |
| Trusted timestamps | RFC 3161 — DigiCert (`http://timestamp.digicert.com`) |
| Certificates | OpenSSL dev CA with a published CRL; commercial CA or CCA-licensed ESP in production |
| Local orchestration | Docker Compose |
| Tests | Pest (API) · Vitest (UI) · Python integration scripts |

Three processes: a React SPA, a Laravel API, and a Python sealing service that
the API calls over a private network.

---

## 2. Why this stack — and why not the alternatives

### Laravel for the API

The feature list is almost exactly Laravel's standard equipment: queues for the
sealing pipeline, mail with an SES driver, an S3 storage abstraction, Sanctum
tokens, policies, a scheduler for expiry sweeps. None of that had to be built.
It is also the framework I have shipped production work in, so the review effort
went into the signing logic rather than into learning a framework.

### React for the UI

Two screens carry real interaction state: the field-placement editor
(drag-to-place, zoom, per-page coordinate mapping, undo) and the signature pad
(canvas drawing, image upload, live font preview). A component model with local
state fits that far better than server-rendered templates with sprinkled
interactivity.

### A Python sealing service — necessity, not preference

This is the most important decision in the project, and it was forced.

**PAdES B-LTA cannot be produced in PHP with free libraries.** I evaluated four
options before adding a second language:

| Option | Verdict |
|---|---|
| **FPDI** (free) | Cannot read PDF 1.5+ at all. PDF 1.5 introduced compressed cross-reference streams and object streams; the open-source parser does not support them, and most real-world PDFs are 1.5 or later. Reading them needs the **commercial** FPDI PDF-Parser add-on. |
| **TCPDF `setSignature()`** | Produces only a basic `adbe.pkcs7.detached` signature — no PAdES subfilter, no DSS dictionary, no document-timestamp chain. Structurally incapable of exceeding B-B. |
| **SetaPDF-Signer** | Implements PAdES properly. Commercially licensed. |
| **pyHanko** (Python) | Open source, covers B-B / B-T / B-LT / **B-LTA** with full LTV. |

So the choice was: buy a licence, ship a weaker signature and describe it as
something it isn't, or add roughly 200 lines of Python behind one internal HTTP
call. The last option is the only one that delivers what was asked for, at zero
licence cost, with the cryptography handled by a library that specialises in it.

The service is small and single-purpose: stamp artefacts, append the certificate
of completion, seal, verify. It holds no state and no database credentials.

### PostgreSQL, not MySQL

`jsonb` with GIN indexing keeps audit payloads queryable without a second
denormalised table; `CHECK` constraints are expressive enough to enforce the
envelope state machine and field-coordinate bounds in the database rather than
only in application code.

One caveat found in practice and worth knowing: **`jsonb` does not preserve
object key order** — it returns keys sorted by length then bytewise. That broke
the audit hash chain on the first run, because a payload written in one order
re-serialised in another and the recomputed hash no longer matched. The fix was
to canonicalise (recursively sort keys) before hashing, the same idea as RFC 8785.
`json` would preserve the text but lose the indexing.

### PostgreSQL, not MongoDB

The domain is inherently relational — envelope → recipient → field → value — and
finalising a signature is a multi-table transaction. An evidence log needs strict
ordering and referential integrity, which is the opposite of what eventual
consistency offers.

### Object storage, not database blobs

PDFs run from 100 KB to 20 MB. Blobs bloat backups, slow replication and cannot
be streamed. S3 also brings versioning, lifecycle rules, SSE-KMS and pre-signed
URLs at no additional effort.

### Queues, not synchronous sealing

Sealing makes a network round trip to a timestamp authority and fetches
revocation data. It takes seconds and can fail for reasons unrelated to the
signer. It retries with backoff (10s → 30s → 2m → 5m) rather than turning a
transient TSA outage into a failed signature.

### Why not Next.js

A reasonable stack, but I would have been learning a framework and implementing
cryptography at the same time. The Python service is required regardless, so the
single-language argument for a Node backend disappears.

### Why not Node/Express

Queues, mail, storage and auth would all have to be assembled by hand.

### Why not sign in the browser (WebCrypto)

The private key would have to reach the client. That is unacceptable key custody
at any scale, and client-reported coordinates and timestamps cannot be trusted
anyway. All sealing is server-side; the browser only ever produces a preview.

### Why not simply buy DocuSign

Addressed in §3 and §4 — for most teams, buying is the right answer.

---

## 3. Competition and cost

List prices verified August 2026. Annual billing unless stated; monthly billing
typically carries a premium.

### Global

| Product | Price | Notes |
|---|---|---|
| **DocuSign** | Personal **$10/mo** ($15 monthly, 5 envelopes/mo); Standard **$25/user/mo** ($45 monthly); Business Pro **$40/user/mo** ($65 monthly); Enterprise custom | Annual plans capped ≈100 envelopes/user/yr. SMS delivery ≈$0.40+/send, ID verification ≈$2.50+/attempt |
| **Adobe Acrobat Sign** | Standard Individual **$12.99/user/mo**; Pro Individual **$19.99**; Standard Teams **$14.99**; Pro Teams **$23.99**; Acrobat Studio Teams **$29.99** | Standard team capped ≈150 transactions/user/yr; monthly billing ≈50% premium |
| **Dropbox Sign** | from **~$15/user/mo**; free tier 3 docs/mo | API plans from ~$100/mo |
| **PandaDoc** | Essentials **~$19/seat/mo**; Business **~$49/seat/mo** | Document-generation focused |
| **SignNow** | from **~$8/user/mo** | Cheapest mainstream option |
| **Zoho Sign** | free tier; paid from **~$10/user/mo** | |

### India

Structurally different — priced per transaction rather than per seat, which
matters a great deal if signing is embedded in a product rather than used by a
back-office team.

| Product | Price |
|---|---|
| **Leegality** | Licence-free Basic tier: non-Aadhaar eSign **~₹15/signatory**, Aadhaar eSign **~₹25**, e-stamping **₹45+/paper** |
| **Digio** | Contact sales; per-transaction |
| **SignDesk** | Contact sales; per-transaction |
| **eMudhra emSigner / NSDL-Protean** | CCA-licensed ESPs, per-eSign transaction |
| Market range | **₹3–₹25 per signature**, volume-dependent |

### Open source — the real alternative to building from scratch

| Product | Price |
|---|---|
| **DocuSeal** (AGPLv3) | Self-host free; managed from **~€9/mo/instance** |
| **Documenso** (AGPLv3) | Self-host free; cloud from **~$30/mo**; has PAdES support |
| **OpenSign** (AGPLv3) | Self-host free; cloud from **~$30/mo** |

### Break-even

Ten admins sending ~500 envelopes a month:

| | Annual |
|---|---|
| DocuSign Business Pro (10 seats) | **$4,800+** before envelope overages |
| In-house running cost | ~$720 compute + ~$60 storage + ~$12 email + $300–500 document-signing certificate ≈ **$1,100** |

That looks like a clear win until the build is priced in: roughly 120–200 hours
of engineering, plus ongoing patching, certificate renewal, TSA monitoring and
backup verification. Realistic break-even is **12–24 months**.

**The honest conclusion:** build in-house when signing is *a feature of your
product* — embedded, white-labelled, per-transaction economics, deeply integrated
with your own workflow and identity system. Buy when it is *an internal
back-office need*. This project is worth building because it is the former; for
a team that just needs to get contracts signed, DocuSign is cheaper than the
engineer-months.

---

## 4. Security: in-house versus third party

### Real advantages of building it

1. **Data residency and minimisation.** Contracts never leave your VPC. Directly
   relevant to India's DPDP Act 2023 and GDPR Article 44 transfer rules — you
   choose `ap-south-1`, the vendor chooses their own regions.
2. **Blast radius.** An e-signature SaaS is a high-value aggregated target: one
   breach exposes every customer's contracts at once. A single-tenant instance is
   a far less attractive target and its compromise is not systemic.
3. **Key custody.** The signing key lives in your own KMS or HSM. No third party
   can be compelled, phished or tricked into signing on your behalf.
4. **Evidence ownership.** The audit trail is in your database in a format you
   control and can export. If a vendor account lapses or is terminated, their
   audit trails go with it — precisely when litigation makes you want them.
5. **Access-control fidelity.** Signing permissions ride your existing RBAC and
   SSO. No per-seat pricing pressure quietly encouraging shared accounts, which
   is a genuine security anti-pattern created by a commercial model.
6. **No third-party code in the ceremony.** Vendor signing pages load analytics
   and CDN assets; a supply-chain compromise there sits directly inside the
   signing flow. This build serves its own fonts for exactly that reason.
7. **Deletion is real.** You can guarantee hard deletion and a defined retention
   policy. SaaS "delete" is frequently soft-delete plus backups for N years.
8. **No secondary use.** Your documents are not subject to a vendor's terms
   covering analytics or model training.

### The counterweights — which matter just as much

1. You inherit patching, dependency CVEs, certificate lifecycle, TSA
   availability, key rotation and backup-integrity testing. Forever.
2. You have no SOC 2 Type II, ISO 27001, HIPAA BAA or 21 CFR Part 11
   attestation. Enterprise buyers ask for these, and "we built it ourselves" is
   not an answer.
3. In a dispute, DocuSign's audit trail carries two decades of precedent and
   available expert witnesses. Yours is novel, and you must be prepared to prove
   your own process rather than point at an established one.
4. **In India, a legally recognised electronic signature under IT Act §3A
   requires an eSign service from a CCA-licensed ESP bound to Aadhaar or PAN.**
   No amount of engineering substitutes for that licence.

### The position this system takes

A hybrid. Own the interface, the storage, the workflow and the evidence; delegate
identity binding and certificate issuance to a licensed authority. The code
reflects this: a `SignatureSealer` seam lets the same envelope flow route either
to the in-house PAdES sealer or to a CCA-licensed ESP (Digio, Leegality,
NSDL-Protean) without touching anything above it.

---

## 5. Core concepts, and what a signature captures

### 5.1 Electronic signature ≠ digital signature

An **electronic signature** is a *legal* concept: any electronic symbol or
process attached to a record and executed with intent to sign. A typed name
qualifies.

A **digital signature** is a *cryptographic* mechanism binding a key to specific
document bytes. It is the technology commonly used to make an electronic
signature trustworthy, but the two are not the same thing and conflating them is
the most common mistake in this area.

Assurance tiers are jurisdiction-specific:

- **EU (eIDAS):** SES → AES → QES.
- **India (IT Act 2000):** §3 digital signature via a DSC from a CCA-licensed CA;
  §3A electronic signature per the Second Schedule (Aadhaar eSign).
- **US (ESIGN 2000 + UETA):** technology-neutral, no tiers. Validity rests on
  intent, consent, attribution and record retention.

Note what follows from the US position: **the evidence, not the cryptography, is
what makes a US signature hold up.** That is why the audit trail in this system
is treated as a first-class artefact rather than a log file.

### 5.2 What actually happens cryptographically

1. Define the `/ByteRange` — every byte of the PDF except the gap reserved for
   `/Contents`.
2. Hash that range with SHA-256.
3. Sign the digest with the signer's private key (RSA-2048 here).
4. Wrap it in a CMS/PKCS#7 `SignedData` structure carrying the certificate chain
   and signed attributes (content type, message digest, signing time,
   ESS signing-certificate-v2).
5. Write it hex-encoded into `/Contents` as an **incremental update**, so every
   earlier revision of the document survives intact inside the same file.
6. A verifier recomputes the hash over the ByteRange and checks it against the
   signature. Any changed byte breaks it.
7. Trust comes from the certificate chaining to a trusted root — Adobe AATL, the
   EU Trusted List, India's CCA, or the OS store — with revocation checked via
   OCSP or CRL.

### 5.3 Why timestamps and LTV matter

The signer's own clock proves nothing. An **RFC 3161** timestamp authority
countersigns the hash using a clock nobody in the transaction controls,
establishing that the document existed at time *T*.

Certificates also expire and get revoked, so PAdES defines escalating levels:

| Level | Adds | Answers |
|---|---|---|
| **B-B** | PKCS#7 over the ByteRange | Has this been altered? Who holds the key? |
| **B-T** | RFC 3161 timestamp | When was it signed, per an independent clock? |
| **B-LT** | Certificate chain + OCSP/CRL in a DSS dictionary | Still verifiable once the CA's endpoints are gone |
| **B-LTA** | A renewable chain of document timestamps | Still verifiable decades later, after the signing certificate has expired |

**This system produces B-LTA**, verified independently by the pyHanko CLI.

A practical finding worth recording: B-LT and B-LTA require *embeddable
revocation data*, and a bare self-signed certificate has no CRL distribution
point — so it cannot reach those levels at all. The project therefore runs a
small real CA that publishes a real CRL. With the CRL unreachable, an otherwise
perfect signature validates as **INVALID** for want of revocation information;
with it published, the same file validates cleanly. Publishing revocation data
is not an optional extra.

### 5.4 What the ceremony captures — the evidence package

| Category | Captured | Why it matters |
|---|---|---|
| **Identity** | Name, email, phone, IP — and *how* they were verified (link possession, email OTP, SMS, KBA, Aadhaar/ID match) | "Verified" without a method is not evidence of anything |
| **Intent** | An explicit affirmative act — "I agree — sign this document" — recorded as its own event, distinct from filling fields in | UETA and ESIGN both turn on the signature being executed *with intent to sign* |
| **Consent** | Consent to transact electronically, with the disclosure **version** and a hash of the exact text, plus timestamp and IP | The question in a dispute is never "did they consent" but "to what wording" |
| **Attribution** | IP, user agent, session, which token was used, every authentication event including failures | Failed attempts are evidence too |
| **Timeline** | sent → delivered → opened → viewed → field completed → signed → completed, each in UTC | Establishes sequence, not just outcome |
| **Document integrity** | SHA-256 of the file as uploaded, after signatures are applied, and after sealing | Proves the starting point as well as the end state |
| **The artefact** | The signature PNG, whether drawn/uploaded/typed, the font if typed, page and coordinates | The mark itself is part of the record |
| **Trusted time** | An RFC 3161 token from an independent authority | Never the application server's clock |
| **Tamper evidence** | Hash-chained audit log **and** the PDF's own cryptographic seal | The record of events and the document are separately verifiable |
| **Certificate of Completion** | A human-readable PDF appended to the document summarising all of the above | In practice this is the page that actually gets read in a dispute |

### 5.5 On the limits of a hash chain

Each audit event's hash covers the previous event's hash, so editing, deleting or
reordering any event invalidates every hash that follows. `signdesk:verify-audit`
recomputes the whole chain and exits non-zero on a break, and PostgreSQL triggers
reject `UPDATE` and `DELETE` on the table outright.

It is worth being precise about what that does and does not achieve. A hash chain
makes tampering **detectable**, not **impossible** — an attacker with write access
could rebuild the chain from the point of the edit onward. What closes that gap is
that the chain's contents end up inside a PAdES-signed PDF, timestamped by an
authority outside your control. A rewritten chain can no longer be made to match
the sealed document, and the timestamp cannot be backdated. Neither mechanism is
sufficient alone; together they are strong.

---

## Sources

Pricing and library capabilities, checked August 2026:

- [DocuSign pricing](https://signeasy.com/blog/business/docusign-pricing) · [costbench](https://costbench.com/software/e-signature/docusign/)
- [Adobe Acrobat Sign pricing](https://signeasy.com/blog/business/adobe-sign-pricing) · [costbench](https://costbench.com/software/e-signature/adobe-sign/)
- [SignNow vs Dropbox Sign](https://www.unkoa.com/signnow-vs-dropbox-sign-hellosign-in-2025-which-gives-small-teams-more-value-for-the-money/) · [Zoho Sign pricing](https://www.zoho.com/sign/pricing.html)
- [Leegality eSign & eStamp pricing](https://productgrowth.in/tools/kyc-identity/leegality/) · [India eSign pricing comparison](https://signyu.com/compare/pricing) · [eSign in India guide](https://peko.one/in/blogs/company-formation/esign-in-india)
- [Open-source DocuSign alternatives](https://sliplane.io/blog/5-open-source-docusign-alternatives) · [DocuSeal pricing](https://eversign.com/blog/docuseal-pricing-guide)
- [pyHanko signing documentation](https://docs.pyhanko.eu/en/latest/lib-guide/signing.html) · [pyHanko repository](https://github.com/MatthiasValvekens/pyHanko)
- [FPDI limitations — PDF 1.5+ compressed cross-reference streams unsupported by the free parser](https://manuals.setasign.com/fpdi-manual/v2/limitations/) · [SetaPDF-Signer PAdES module](https://manuals.setasign.com/setapdf-signer-manual/signature-modules/pades/)
- [FreeTSA — free RFC 3161 timestamp authority](https://www.freetsa.org/index_en.php)
