# SignDesk — Digital Document Signing

**Live:** <https://docsignass.faisalkhan.cloud/demo> · **Repository:** <https://github.com/samfaiz/DocuSignAssignment>

An administrator uploads a PDF, places fields on it, and emails each recipient a
unique link. The signer verifies a one-time passcode, consents to using
electronic records, and signs — by drawing, by uploading an image of their
signature, or by typing their name in a script font. The finished document is
stamped, has a certificate of completion appended, and is sealed with a
**PAdES-B-LTA** digital signature carrying an RFC 3161 trusted timestamp from
DigiCert.

---

## Contents

1. [The assignment](#1-the-assignment)
2. [For reviewers — start here](#2-for-reviewers--start-here)
3. [What it does](#3-what-it-does)
4. [Tech stack](#4-tech-stack)
5. [Why this stack, and why not the alternatives](#5-why-this-stack-and-why-not-the-alternatives)
6. [Working flow](#6-working-flow)
7. [Competition and cost](#7-competition-and-cost)
8. [Security: in-house versus buying](#8-security-in-house-versus-buying)
9. [Core concepts of digital signatures](#9-core-concepts-of-digital-signatures)
10. [Security controls implemented](#10-security-controls-implemented)
11. [Privacy: what is captured, and what is deleted](#11-privacy-what-is-captured-and-what-is-deleted)
12. [Engineering notes](#12-engineering-notes)
13. [Known limitations](#13-known-limitations)

---

## 1. The assignment

> **Digital Docu sign** — Admin should be able to send an email with a PDF and a
> link to digitally sign the document on my portal, plus a normal signature
> feature like Adobe Acrobat: import an image (PNG signature), or write a name
> which automatically mimics a signature using a signature-friendly font.
>
> Plan: (1) tech stack, (2) reason for selecting it and why not others,
> (3) competition with monthly costing, (4) security benefits of an in-house
> solution versus third party, (5) core concepts of digital signatures and what
> they capture.

Sections 4, 5, 7, 8 and 9 below answer those five questions. The rest describes
what was actually built, which runs and is deployed.

A PDF version of the written analysis is at
[`docs/SignDesk-Assignment.pdf`](docs/SignDesk-Assignment.pdf), regenerable with
`python docs/build_report.py`. Setup, deployment and the commands that verify
the signature are in [DEPLOYMENT.md](DEPLOYMENT.md).

---

## 2. For reviewers — start here

Open **<https://docsignass.faisalkhan.cloud/demo>**.

- **Start signing ceremony** provisions an agreement and drops you straight into
  the signer's shoes. No account, no terminal.
- The one-time passcode is shown on screen instead of making you hunt through an
  inbox. In production it only ever reaches the signer's email.
- **Guided tour** (bottom right) opens a panel that follows whichever step you
  are on and explains the decision behind it and the alternative rejected. It is
  off by default and only observes — it cannot change what the application does.

Demo administrator: `demo@signdesk.test` / `demo-signdesk-2026`

Both conveniences live behind `DEMO_MODE`, which defaults to on only in the local
environment. The routes do not register anywhere else and the controller
re-checks the flag, because one of them returns a live authentication factor:
with it enabled, anyone who can reach the API could finish somebody else's
signature. `DEMO_MODE=false` removes them entirely.

### Worth trying

| | |
|---|---|
| **The seal is real** | A completed envelope is signed PAdES-B-LTA and timestamped by DigiCert. Open the signed PDF in Adobe Reader, or use the Verify page. |
| **Tampering is detectable** | Download a signed PDF, change one byte in a hex editor, upload it to Verify. It still opens perfectly and reports as altered. |
| **The audit trail is chained** | Each event's hash covers the previous one's, and the database rejects updates and deletes outright. |
| **Send one yourself** | Sign in as the demo admin, use the bundled sample agreement, place fields, send. |

---

## 3. What it does

- **Admin portal** — upload, click-to-place fields per recipient, send, track status
- **Signer portal** — tokenised link → email OTP → consent → sign → done
- **Three signature modes** — draw, upload a PNG/JPEG, or type a name in one of
  five bundled script fonts
- **Server-side stamping** — the browser never decides where ink lands
- **Certificate of Completion** — identity method, consent version, document
  hashes, full event timeline, appended to every signed document
- **PAdES-B-LTA sealing** — PKCS#7 + RFC 3161 timestamp + embedded revocation
  data + a renewable document-timestamp chain
- **Hash-chained, append-only audit trail** enforced by database triggers
- **Optional, per-envelope** — the signer's location and a photograph, both
  consented, both recorded as *reported* rather than verified
- **Enforced retention** — photographs and coordinates deleted on a schedule,
  while the record of what was asked and agreed is kept
- **Admin-managed SMTP** — mail configured from the interface, password
  encrypted at rest
- **Public verification** — upload any PDF and check whether it has been altered

---

## 4. Tech stack

*(assignment question 1)*

| Layer | Choice |
|---|---|
| Admin and signer UI | React 19, TypeScript, Vite 8, Tailwind 4, React Router 7 |
| PDF rendering in the browser | pdf.js 6 (`pdfjs-dist`) |
| API | Laravel 13, PHP 8.3+, Sanctum |
| Database | PostgreSQL 16 |
| Queue and cache | Redis 7 (`predis`) |
| Object storage | S3-compatible, or local disk |
| Email | SMTP — configured from the admin UI |
| **PAdES sealing service** | **Python 3.12, FastAPI, pyHanko 0.36** |
| PDF composition | pypdf, reportlab, Pillow |
| Trusted timestamps | RFC 3161 — DigiCert |
| Certificates | OpenSSL CA publishing a CRL; commercial CA or CCA-licensed ESP in production |
| Local orchestration | Docker Compose |
| Tests | PHPUnit, Python integration scripts, end-to-end ceremony runner |

```
api/           Laravel API
web/           React SPA
sign-service/  FastAPI + pyHanko sealing service
pki/           CA, signing certificate and published CRL
docs/          Written analysis and its PDF build script
```

Three processes: a React SPA, a Laravel API, and a Python sealing service the
API calls over a private network.

---

## 5. Why this stack, and why not the alternatives

*(assignment question 2)*

### Laravel for the API

The feature list is almost exactly Laravel's standard equipment: queues for the
sealing pipeline, mail with an SES driver, an S3 storage abstraction, Sanctum
tokens, authorisation policies, a scheduler for retention and expiry sweeps.
None of that had to be built. It is also the framework I have shipped production
work in, so review effort went into the signing logic rather than into learning
a framework.

### React for the interface

Two screens carry real interaction state: the field-placement editor
(click-to-place, zoom, per-page coordinate mapping) and the signature pad
(canvas drawing, image upload, live font preview). A component model with local
state fits that far better than server-rendered templates with sprinkled
interactivity.

### A Python sealing service — necessity, not preference

This is the most important decision in the project, and it was forced.
**PAdES B-LTA cannot be produced in PHP with free libraries.** Four options were
evaluated before adding a second language:

| Option | Verdict |
|---|---|
| **FPDI** (free) | Cannot read PDF 1.5+ at all. PDF 1.5 introduced compressed cross-reference streams and object streams; the open-source parser does not support them, and most real-world PDFs are 1.5 or later. Reading them requires the **commercial** FPDI PDF-Parser add-on. |
| **TCPDF `setSignature()`** | Produces only a basic `adbe.pkcs7.detached` signature — no PAdES subfilter, no DSS dictionary, no document-timestamp chain. Structurally incapable of exceeding B-B. |
| **SetaPDF-Signer** | Implements PAdES properly. Commercially licensed. |
| **pyHanko** (Python) | Open source, covers B-B, B-T, B-LT and **B-LTA** with full long-term validation. |

So the choice was: buy a licence, ship a weaker signature while describing it as
something it is not, or add roughly 200 lines of Python behind one internal HTTP
call. Only the last delivers what was asked for, at no licence cost, with the
cryptography handled by a library that specialises in it. The service is
stateless — it holds no database credentials and takes bytes in, returning
sealed bytes.

### PostgreSQL, not MySQL

`jsonb` with GIN indexing keeps audit payloads queryable without a second
denormalised table, and `CHECK` constraints are expressive enough to enforce the
envelope state machine and field-coordinate bounds in the database rather than
only in application code.

One caveat found in practice: **`jsonb` does not preserve object key order** —
it returns keys sorted by length then bytewise. That broke the audit hash chain
on the first run, because a payload written in one order re-serialised in
another and the recomputed hash no longer matched. The fix was to canonicalise
(recursively sorting keys) before hashing, the same idea as RFC 8785.

### PostgreSQL, not MongoDB

The domain is inherently relational — envelope → recipient → field → value — and
finalising a signature is a multi-table transaction. An evidence log needs strict
ordering and referential integrity, which is the opposite of what eventual
consistency offers.

### Object storage, not database blobs

PDFs run 100 KB–20 MB. Blobs bloat backups, slow replication and cannot be
streamed. S3 also brings versioning, lifecycle rules, server-side encryption and
pre-signed URLs at no extra effort. The disk is configurable, so a single-server
deployment can use local storage with no code change.

### Queues, not synchronous sealing

Sealing makes a network round trip to a timestamp authority and fetches
revocation data. It must be retryable with backoff, not blocking an HTTP request.

### Why not Next.js

A good stack, but it would have meant learning a framework *and* implementing
cryptography simultaneously. The Python service is required regardless, so the
single-language argument disappears.

### Why not Node and Express

Queues, mail, storage and authentication would all have to be assembled by hand.

### Why not sign in the browser with WebCrypto

The private key would have to reach the client. That is unacceptable key custody
at any scale, and client-reported coordinates and timestamps cannot be trusted
anyway. All sealing is server-side; the browser only ever produces a preview.

---

## 6. Working flow

Three stages. The first two are interactive; the third runs on a queue.

### Stage 1 — the admin prepares and sends

**Upload.** The file's magic bytes are checked for `%PDF-`, then it is handed to
the Python service, where a real parser confirms it opens, is not
password-protected, and reports its page count. Only then is it stored under a
random key — never the user's filename — and its SHA-256 recorded. That hash is
the anchor everything downstream is compared against.

**Field placement.** pdf.js renders the pages and clicking drops a field for the
selected recipient. Coordinates are stored as fractions of the page between 0
and 1 with a top-left origin, not pixels, so a placement survives zoom, screen
DPI and the later conversion into PDF user space. Every field belongs to exactly
one recipient, which is what makes "you cannot fill someone else's field"
enforceable rather than merely hidden in the interface.

**Send.** Each recipient gets a 256-bit random token. Only its SHA-256 is stored,
so a database dump yields no working links. Invitations are queued.

### Stage 2 — the signer completes the ceremony

Signers have no account, so every request re-establishes who is calling from the
token alone. The token is looked up by hash and compared in constant time; an
expired link returns 410 and an unknown one 404.

**Passcode.** Six digits, hashed with bcrypt rather than SHA-256 — a million
possibilities would fall to an offline sweep against a fast hash — expiring in
ten minutes and locking out after five failures. The document is not served
until this passes: possession of a forwarded link is not enough.

**Consent.** Records the disclosure version and a SHA-256 of the exact text
shown, not a boolean. The question in a dispute is never whether someone
consented but to what wording.

**Optional evidence.** If the sender enabled it, dialogs ask for the signer's
location and a photograph. Both are declinable, both decisions are recorded, and
neither blocks signing.

**Signing.** Typed names are rendered to PNG on the server, so the artefact
sealed into the document does not depend on which fonts the signer happened to
have installed. Uploaded images are fully decoded and re-encoded, which strips
EXIF and anything else riding along with the pixels. Clicking the final button is
logged as its own intent event, separate from having filled the fields in — that
distinction is what the ESIGN Act and UETA actually turn on. The token is then
burned.

### Stage 3 — the server seals and delivers

Queued, with widening backoff, because sealing makes a network round trip that
can fail for reasons nothing to do with the signer.

The job reads field coordinates **from the database, never from the finishing
request**, so a signer cannot move their own signature elsewhere in the document
on the way out. It builds the certificate payload from the audit trail, then
makes a single call to the Python service, which composites the marks, appends
the evidence page and applies the seal in one pass — the half-finished document,
carrying signatures but no cryptographic protection, never touches disk. The
level actually achieved is stored, not the level requested.

### Running underneath all of it

Every step writes a hash-chained audit event. Each row's hash covers the previous
row's, and PostgreSQL rejects `UPDATE` and `DELETE` outright. That chain is
printed into the Certificate of Completion, which then goes inside the sealed,
timestamped PDF — so the record of what happened and the document itself end up
cross-witnessing each other.

---

## 7. Competition and cost

*(assignment question 3)*

List prices verified August 2026. Annual billing unless stated.

### Global

| Product | Price | Notes |
|---|---|---|
| **DocuSign** | Personal **$10/mo** ($15 monthly, 5 envelopes/mo); Standard **$25/user/mo** ($45 monthly); Business Pro **$40/user/mo** ($65 monthly) | Annual plans capped ≈100 envelopes/user/yr. SMS delivery ≈$0.40+/send, ID verification ≈$2.50+/attempt |
| **Adobe Acrobat Sign** | Standard Individual **$12.99/user/mo**; Pro Individual **$19.99**; Standard Teams **$14.99**; Pro Teams **$23.99** | Standard team capped ≈150 transactions/user/yr; monthly billing ≈50% premium |
| **Dropbox Sign** | From **~$15/user/mo**; free tier 3 docs/mo | API plans from ~$100/mo |
| **PandaDoc** | Essentials **~$19/seat/mo**; Business **~$49/seat/mo** | Document-generation focused |
| **SignNow** | From **~$8/user/mo** | Cheapest mainstream option |
| **Zoho Sign** | Free tier; paid from **~$10/user/mo** | |

### India

Structurally different — priced per transaction rather than per seat, which
matters if signing is embedded in a product rather than used by a back-office
team.

| Product | Price |
|---|---|
| **Leegality** | Licence-free Basic: non-Aadhaar eSign **~₹15/signatory**, Aadhaar eSign **~₹25**, e-stamping **₹45+/paper** |
| **Digio** | Contact sales; per transaction |
| **SignDesk** | Contact sales; per transaction |
| **eMudhra emSigner / NSDL-Protean** | CCA-licensed ESPs, per eSign transaction |
| Market range | **₹3–₹25 per signature**, volume-dependent |

### Open source — the real alternative to building

| Product | Price |
|---|---|
| **DocuSeal** (AGPLv3) | Self-host free; managed from **~€9/mo/instance** |
| **Documenso** (AGPLv3) | Self-host free; cloud from **~$30/mo**; has PAdES support |
| **OpenSign** (AGPLv3) | Self-host free; cloud from **~$30/mo** |

### Break-even

Ten administrators, ~500 envelopes a month:

| | Annual |
|---|---|
| DocuSign Business Pro, 10 seats | **$4,800+** before envelope overages |
| In-house running cost | ~$720 compute + ~$60 storage + ~$12 email + $300–500 document-signing certificate ≈ **$1,100** |

That looks like a clear win until the build is priced in: roughly 120–200 hours
of engineering, plus ongoing patching, certificate renewal, TSA monitoring and
backup verification. Realistic break-even is **12–24 months**.

**The honest conclusion:** build in-house when signing is *a feature of your
product* — embedded, white-labelled, per-transaction economics, deeply
integrated with your own workflow and identity system. Buy when it is *an
internal back-office need*. This project is worth building because it is the
former; for a team that simply needs contracts signed, DocuSign is cheaper than
the engineer-months.

---

## 8. Security: in-house versus buying

*(assignment question 4)*

### Real advantages of building it

1. **Data residency and minimisation.** Contracts never leave your own network.
   Directly relevant to India's DPDP Act 2023 and GDPR Article 44 transfer rules:
   you pick the region, the vendor picks theirs.
2. **Blast radius.** An e-signature SaaS is a high-value aggregated target — one
   breach exposes every customer's contracts at once. A single-tenant instance is
   far less attractive and its compromise is not systemic.
3. **Key custody.** The signing key lives in your own KMS or HSM. No third party
   can be compelled, phished or tricked into signing on your behalf.
4. **Evidence ownership.** The audit trail is in your database in a format you
   control and can export. If a vendor account lapses, their audit trails go with
   it — precisely when litigation makes you want them.
5. **Access-control fidelity.** Signing permissions ride your existing roles and
   SSO. No per-seat pricing quietly pushing teams into account-sharing, which is a
   real security anti-pattern created by a commercial model.
6. **No third-party code in the ceremony.** Vendor signing pages load analytics
   and CDN assets; a supply-chain compromise there sits directly inside the
   signing flow. This build serves its own fonts for that reason.
7. **Deletion is real.** You can guarantee hard deletion and a defined retention
   policy — and here it is enforced by a scheduled command, not just described.
8. **No secondary use.** Your documents are not subject to a vendor's terms
   covering analytics or model training.

### The counterweights, which matter just as much

1. You inherit patching, dependency CVEs, certificate lifecycle, TSA
   availability, key rotation and backup-integrity testing. Permanently.
2. You have no SOC 2 Type II, ISO 27001, HIPAA BAA or 21 CFR Part 11
   attestation. Enterprise buyers ask, and "we built it ourselves" is not an
   answer.
3. In a dispute, DocuSign's audit trail carries two decades of precedent and
   available expert witnesses. Yours is novel, and you must be prepared to prove
   your own process rather than point at an established one.
4. **In India, a legally recognised electronic signature under IT Act §3A
   requires an eSign service from a CCA-licensed ESP bound to Aadhaar or PAN.**
   No amount of engineering substitutes for that licence.

### The position this system takes

A hybrid. Own the interface, the storage, the workflow and the evidence;
delegate identity binding and certificate issuance to a licensed authority. The
code reflects this: a sealer seam lets the same envelope flow route either to the
in-house PAdES sealer or to a CCA-licensed ESP, without touching anything above
it.

---

## 9. Core concepts of digital signatures

*(assignment question 5)*

### An electronic signature is not a digital signature

An **electronic signature** is a legal concept: any electronic symbol or process
attached to a record and executed with intent to sign. A typed name qualifies.
A **digital signature** is a cryptographic mechanism binding a key to specific
document bytes. The second is the technology commonly used to make the first
trustworthy, but they are not the same thing, and conflating them is the most
common mistake in this area.

Assurance tiers are jurisdiction-specific:

- **EU (eIDAS)** — simple, advanced, then qualified (SES → AES → QES)
- **India (IT Act 2000)** — §3 digital signature via a DSC from a CCA-licensed
  authority; §3A electronic signatures per the Second Schedule, such as Aadhaar
  eSign
- **US (ESIGN + UETA)** — technology-neutral, no tiers; validity rests on intent,
  consent, attribution and record retention

A consequence worth stating: **in the US the evidence, not the cryptography, is
what makes a signature enforceable.** That is why the audit trail here is treated
as a first-class artefact rather than a log file.

### What actually happens cryptographically

1. Define the `/ByteRange` — every byte of the PDF except the gap reserved for
   the signature itself
2. Hash that range with SHA-256
3. Sign the digest with the signer's private key
4. Wrap it in a CMS/PKCS#7 `SignedData` structure with the certificate chain and
   signed attributes
5. Write it into the reserved gap as an **incremental update**, so every earlier
   revision survives intact inside the same file
6. A verifier recomputes the hash over the byte range and checks it against the
   signature — any changed byte breaks it
7. Trust comes separately, from the certificate chaining to a trusted root, with
   revocation checked by OCSP or CRL

A signature cannot sign itself: embedding it would change the bytes it just
hashed. That is why the PDF reserves a hole and a ByteRange array declares which
spans are covered.

### Why timestamps and long-term validation matter

The signer's own clock proves nothing — it can be set to anything. An RFC 3161
timestamp authority countersigns the hash using a clock nobody in the transaction
controls. Certificates also expire and are revoked, so PAdES defines escalating
levels:

| Level | Adds | Answers |
|---|---|---|
| B-B | PKCS#7 over the byte range | Has this been altered? Who held the key? |
| B-T | RFC 3161 timestamp | When was it signed, by an independent clock? |
| B-LT | Chain and revocation data in a DSS dictionary | Still verifiable once the authority's endpoints are gone |
| **B-LTA** | A renewable chain of document timestamps | Still verifiable decades later, after the signing certificate expires |

**This system produces B-LTA**, confirmed independently by pyHanko's CLI. A
practical finding: B-LT and B-LTA require *embeddable* revocation data, and a
bare self-signed certificate has no CRL distribution point, so it cannot reach
those levels. With the CRL unreachable an otherwise perfect signature validates
as INVALID; with it published, the same file validates cleanly.

### What the ceremony captures — the evidence package

| Category | Captured | Why it matters |
|---|---|---|
| **Identity** | Name, email, phone, IP — and *how* they were verified | "Verified" without a method is not evidence of anything |
| **Intent** | An explicit affirmative act, recorded as its own event | UETA and ESIGN turn on the signature being executed with intent to sign |
| **Consent** | Disclosure version and a hash of the exact text, with time and IP | The question is never whether they consented, but to what wording |
| **Attribution** | IP, user agent, which token was used, every auth event including failures | Failed attempts are evidence too |
| **Timeline** | Sent, delivered, opened, viewed, field completed, signed, completed — in UTC | Establishes sequence, not just outcome |
| **Document integrity** | SHA-256 as uploaded, after signatures, and after sealing | Proves the starting point as well as the end state |
| **The artefact** | The signature image, whether drawn/uploaded/typed, font, page and coordinates | The mark itself is part of the record |
| **Location** *(optional)* | Coordinates and accuracy, with the consent decision | Signer-reported, never server-observed |
| **Photograph** *(optional)* | An image captured at signing, with the consent decision | Evidence of presence, explicitly **not** identity verification |
| **Trusted time** | An RFC 3161 token from an independent authority | Never the application server's clock |
| **Tamper evidence** | A hash-chained audit log *and* the PDF's own seal | The record and the document are separately verifiable |
| **Certificate of Completion** | A human-readable PDF appended to the document | In practice this is the page that gets read in a dispute |

### On the limits of a hash chain

Each event's hash covers the previous event's, so editing, deleting or reordering
any event invalidates every hash that follows. `signdesk:verify-audit` recomputes
the whole chain, and PostgreSQL triggers reject `UPDATE` and `DELETE` outright.

Being precise about what that achieves: a hash chain makes tampering
**detectable**, not **impossible** — an attacker with write access could rebuild
the chain from the edit onward. What closes the gap is that the chain's contents
end up inside a PAdES-signed PDF, timestamped by an authority outside your
control. A rewritten chain can no longer be made to match the sealed document,
and the timestamp cannot be backdated. Neither mechanism is sufficient alone.

---

## 10. Security controls implemented

- Signing tokens are 256-bit and stored only as SHA-256 — a database dump yields
  no working links. Tokens are burned on completion.
- A one-time passcode is required before the document is served. Passcodes are
  bcrypt-hashed, expire in ten minutes, and lock out after five failures.
- Rate limits are layered: per-IP throttles blunt automation, while the limits
  that protect a *specific* signer are per-recipient. Per-IP limits are
  deliberately loose because everyone behind one office NAT shares a counter.
- Every field is scoped to its recipient in the query itself, so another signer's
  field identifier resolves to nothing. Covered by an explicit IDOR test.
- Uploaded images are decoded and re-encoded server-side, stripping EXIF —
  including the GPS tags phone cameras write. PDFs are validated by a real
  parser, not by extension or declared content type.
- The audit table rejects `UPDATE` and `DELETE` at the database level, and each
  row's hash covers the previous row's.
- The sealing service has no public ingress and authenticates every request with
  a shared-secret HMAC over the exact request body.
- Login is throttled per email-and-IP pair and answers identically for an unknown
  account and a wrong password, so it cannot be used to enumerate users.
- Mail credentials are encrypted at rest and never returned by the API.

---

## 11. Privacy: what is captured, and what is deleted

Location and photograph are both **optional, consented, and per-envelope**.
Declining is recorded as a decision, never blocks signing, and the refusal button
sits beside the other one at the same size.

Three deliberate choices:

**Photographs are sender-enabled, not always on.** A face image is
special-category data under GDPR Article 9 once used to identify someone, carries
heightened duties under India's DPDP Act, and is actionable per violation under
Illinois BIPA. Collecting it from every signer regardless of the document would
be indefensible.

**Nothing is called identity verification.** Without a government document, a
face match against it and liveness detection, a photograph establishes that
someone was present and willing to be photographed — not who they are. The
certificate says so in those words. Real KYC belongs with a licensed provider.

**Retention is enforced, not merely described.** `signdesk:purge-evidence` runs
daily: photographs deleted after 90 days, coordinates after 365, both
configurable. Only the sensitive artefact goes — the record that a photograph was
requested and that the signer agreed or refused is kept forever, because that is
the part with evidential value and it holds no personal data. The purge is
written into the audit chain so nothing is removed silently.

One limitation stated plainly: **a sealed document already delivered is beyond
recall.** It carries its own copy and is tamper-evident, so nothing can be
removed from it. Retention here means "we stop holding it", not "it ceases to
exist" — an inherent tension, since the property that makes the evidence
trustworthy is the same one that makes it unretractable.

---

## 12. Engineering notes

Bugs worth recording, because several were only reachable in production and each
one taught something:

**`jsonb` reorders object keys.** The audit hash chain broke on its first real
run: a payload written in one key order came back in another, re-serialised
differently, and the recomputed hash no longer matched. Fixed by canonicalising
keys before hashing (RFC 8785's approach). There is a regression test.

**Queued mailables cannot carry binary.** `SignedCopy` held the raw PDF as a job
property; the payload failed to JSON-encode and **the signed copy silently never
sent**. The end-to-end suite passed anyway because it did not check delivery — so
an assertion was added.

**Laravel redirects unauthenticated guests to `route('login')`,** which does not
exist in an API-plus-SPA application. Any request without
`Accept: application/json` produced a 500 where a 401 belonged. Invisible in
development, immediate in production where crawlers open API URLs directly.

**nginx does not know `.mjs`.** pdf.js loads its worker via dynamic `import()`,
which browsers MIME-check strictly. Served as `application/octet-stream`, the
browser refuses it and the document never renders. Rather than configure every
future host, the worker is now copied to `public/` as `.js` at build time.

**A React ref is null on the line after `setState`.** The camera preview stayed
black because `srcObject` was assigned before the `<video>` had rendered.
Attaching it in an effect keyed on the visibility flag fixed it. Found only by
installing a fake camera and driving the real component — testing the endpoint
had proved nothing about the browser path.

**pdf.js needs a foreground tab.** Rendering is scheduled through
`requestAnimationFrame`, which browsers suspend while `document.hidden` is true,
so the render promise never resolves in a background tab.

---

## 13. Known limitations

- **The development CA is not a public trust anchor.** Adobe shows the signature
  as valid only after its certificate is trusted manually. Production needs a
  commercial certificate, or a CCA-licensed ESP for IT Act §3A recognition. The
  sealer seam exists for exactly that swap.
- **No per-signer digital signature.** The seal is an organisational one, as with
  DocuSign and Adobe Sign — the signer is bound by the evidence package, not by a
  key they control. A signer-held key requires a CA or ESP.
- **No Vitest suite for the SPA.** The interactive behaviour that matters needs
  heavy browser mocking; coverage came from the end-to-end run and manual
  walkthroughs. A real gap, not a design choice.
- **Horizon is not used.** It needs `pcntl`/`posix`, unavailable on Windows;
  `queue:work` covers the same ground.
- **Multi-signer routing order** is enforced server-side but has not been
  exercised end to end.
- **Backups must include `storage/app`.** With local disk, a database backup
  alone loses every document, signature image and photograph.

---
