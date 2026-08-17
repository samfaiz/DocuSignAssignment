# SignDesk

A digital document signing platform. An administrator uploads a PDF, places
fields on it and emails each recipient a unique link. The signer verifies a
one-time passcode, consents to using electronic records, and signs — by drawing,
by uploading an image of their signature, or by typing their name in a script
font. The finished document is stamped, has a certificate of completion appended,
and is sealed with a **PAdES-B-LTA** digital signature carrying an RFC 3161
trusted timestamp.

The written analysis that accompanies this build — stack justification,
competitor pricing, in-house versus third-party security, and the core concepts
of digital signatures — is in **[`docs/ANALYSIS.md`](docs/ANALYSIS.md)**.

---

## For reviewers — start here

Once the stack is running (see below), open **<http://localhost:5173/demo>**.

- **Start signing ceremony** provisions an agreement and drops you straight into
  the signer's shoes. No account, no terminal.
- The one-time passcode is shown on screen instead of making you hunt through the
  mail catcher. In production it only ever reaches the signer's inbox.
- **Guided tour** (bottom-right) opens a panel that follows whichever step you
  are on and explains the decision behind it and the alternative rejected. It is
  off by default and only observes — it cannot change what the application does,
  so the flow you test is the real one.

Both conveniences live behind `DEMO_MODE`, which defaults to on only in the local
environment. The routes do not register anywhere else and the controller
re-checks the flag, because one of them returns a live authentication factor:
with it enabled, anyone who can reach the API could finish somebody else's
signature. Set `DEMO_MODE=false` to remove them entirely.

## What it does

- Admin portal: upload, click-to-place fields per recipient, send, track status
- Signer portal: tokenised link → email OTP → consent → sign → done
- Three signature modes: **draw**, **upload a PNG/JPEG**, **type a name** in one
  of five bundled script fonts
- Server-side stamping — the browser never decides where ink lands
- Auto-generated Certificate of Completion (identity method, consent version,
  document hashes, full event timeline)
- **PAdES-B-LTA** sealing: PKCS#7 + RFC 3161 timestamp + embedded revocation data
  + a renewable document-timestamp chain
- Optional, per-envelope: the signer's location and a photograph, both consented
  and both recorded as *reported* rather than verified
- Hash-chained, append-only audit trail enforced by database triggers
- Enforced retention: photographs and coordinates are deleted on a schedule,
  while the record of what was asked and agreed is kept
- Public verification page — upload any PDF and check whether it has been altered

## Architecture

```
React SPA  ──/api──▶  Laravel 13 API  ──HMAC, private net──▶  Python sealing service
 pdf.js               PostgreSQL 16                            pyHanko
 signature canvas     Redis (queues)                              │
                      S3 / MinIO                        ┌─────────┴─────────┐
                      SMTP                          RFC 3161 TSA      Internal CA
                                                     (DigiCert)      (publishes a CRL)
```

**Why a second language?** No free PHP library can produce PAdES B-LT or B-LTA.
FPDI's open-source parser cannot read PDF 1.5+ at all, TCPDF's `setSignature()`
emits only a basic `adbe.pkcs7.detached` signature with no DSS or timestamp
chain, and SetaPDF-Signer is commercially licensed. pyHanko is the only mature
open-source implementation. The full comparison is in
[`docs/ANALYSIS.md`](docs/ANALYSIS.md#2-why-this-stack--and-why-not-the-alternatives).

```
api/           Laravel 13 API (PHP 8.3)
web/           React 19 + Vite + TypeScript SPA
sign-service/  FastAPI + pyHanko sealing service (Python 3.12)
pki/           Development CA, signing certificate and published CRL
docs/          The written analysis
```

---

## Running it

Requires PHP 8.3 (with `pdo_pgsql`), Composer, Node 20+, Python 3.12, Docker and
OpenSSL.

### 1. Infrastructure

```bash
docker compose up -d postgres redis minio minio-init mailpit pki-web
```

Postgres is published on **55432** and Redis on **63790** — deliberately high, so
a native PostgreSQL install on 5432/5433 cannot answer instead (which surfaces as
a confusing authentication failure rather than a connection error).

### 2. Certificates

```bash
bash pki/scripts/gen-pki.sh
```

Creates a root CA, a document-signing certificate and a CRL. The CRL is served by
the `pki-web` container on port 8080 — **this is required**: PAdES B-LT/B-LTA
embed revocation data, and with the distribution point unreachable an otherwise
valid signature verifies as INVALID.

### 3. Sealing service

```bash
cd sign-service
python -m venv .venv && ./.venv/Scripts/python.exe -m pip install -r requirements.txt
python scripts/fetch_fonts.py
PKI_DIR=../pki/out ./.venv/Scripts/python.exe -m uvicorn app.main:app --port 8001
```

### 4. API

```bash
cd api
composer install
php artisan migrate --seed
php artisan serve --port=8000
php artisan queue:work        # in a second terminal — sealing runs on the queue
```

Seeded administrator: `admin@signdesk.test` / `password`

> The queue worker holds the application in memory. Restart it (or run
> `php artisan queue:restart`) after changing any code it touches.

### 5. SPA

```bash
cd web
npm install
npm run dev
```

Open <http://localhost:5173>. Sent mail is captured by Mailpit at
<http://localhost:8025>.

---

## Verifying it works

### End-to-end ceremony

```bash
./sign-service/.venv/Scripts/python.exe api/tests/e2e/ceremony.py
```

Drives the real HTTP API exactly as the SPA does — uploads, builds an envelope,
sends it, reads the signing link and passcode out of Mailpit, signs, waits for the
queue to seal, confirms the signed copy is delivered, then verifies the result
and probes the access controls. **46 checks.** It writes the finished document to
`api/tests/e2e/sealed-e2e.pdf`.

To drive the signer portal by hand instead:

```bash
./sign-service/.venv/Scripts/python.exe api/tests/e2e/make_link.py
```

### The signature is real

```bash
cd sign-service
./.venv/Scripts/pyhanko.exe sign validate --pretty-print --trust ../pki/out/ca.pem tmp/5-sealed.pdf
```

An independent validator — not this codebase — should report:

```
The signature is cryptographically sound.
TSA certificate subject: "... DigiCert SHA256 RSA4096 Timestamp Responder ..."
The TSA certificate is trusted.
Bottom line: The signature is judged VALID.
```

In Adobe Acrobat Reader, after trusting `pki/out/ca.pem` under
*Preferences → Signatures → Identities & Trusted Certificates*, the signature
panel shows the document as signed and **LTV enabled**.

### Tamper detection

```bash
cd sign-service && PKI_DIR=../pki/out ./.venv/Scripts/python.exe scripts/smoke_test.py
```

Seals a document, then changes one byte of page content and re-validates. The
altered file still opens perfectly and its signature reports as broken — which is
exactly the point. Confirm independently:

```bash
./.venv/Scripts/pyhanko.exe sign validate --executive-summary --trust ../pki/out/ca.pem tmp/6-tampered.pdf
```

→ `INVALID`

### Audit chain

```bash
cd api && php artisan signdesk:verify-audit
```

Recomputes every envelope's hash chain and exits non-zero on any break.

### Test suites

```bash
cd api && php artisan test                                    # 27 tests
cd sign-service && ./.venv/Scripts/python.exe scripts/http_test.py   # 24 checks
```

The PHP tests run against PostgreSQL, not SQLite — the schema depends on `jsonb`,
GIN indexes and a plpgsql trigger, and testing against another engine would skip
the guarantees the tests exist to prove. Create the database once:

```bash
docker exec sd_postgres psql -U signdesk -d signdesk -c "CREATE DATABASE signdesk_test OWNER signdesk;"
```

---

## Security notes

- Signing tokens are 256-bit and stored only as SHA-256 — a database dump yields
  no working links. Tokens are burned on completion.
- A one-time passcode is required before the document is served. Possession of a
  forwarded link is not enough. Passcodes are bcrypt-hashed and lock out after
  five failures.
- Every field is scoped to its recipient in the query itself, so another signer's
  field id resolves to nothing (covered by an explicit IDOR test).
- Uploaded images are fully decoded and re-encoded server-side, which strips EXIF
  and anything else riding along with the pixels. PDFs are validated by a real
  parser, not by extension or content type.
- `audit_events` rejects `UPDATE` and `DELETE` at the database level, and each
  row's hash covers the previous row's.
- A hash chain makes tampering *detectable*, not impossible — someone with write
  access could rebuild it. What closes that gap is that the chain's contents end
  up inside a timestamped, PAdES-signed PDF that cannot be retroactively matched
  to a rewritten chain.

## Known limitations

- **The dev CA is not a public trust anchor.** Adobe shows the signature as valid
  only after you trust `pki/out/ca.pem`. Production needs a certificate from a
  commercial CA — or, for India's IT Act §3A recognition, a CCA-licensed ESP. The
  `SignatureSealer` seam exists for exactly that swap.
- **Horizon is not used.** It requires `pcntl`/`posix`, which are unavailable on
  Windows; `queue:work` covers the same ground here.
- **No Vitest suite for the SPA.** The interactive behaviour that matters (canvas
  drawing, pdf.js rendering) needs heavy browser mocking to unit-test; coverage
  came instead from the 46-check end-to-end run and a manual walkthrough of both
  portals. This is a real gap, not a deliberate design choice.
- **PDF rendering needs a foreground tab.** pdf.js schedules rendering through
  `requestAnimationFrame`, which browsers suspend while `document.hidden` is
  true, so `page.render()` never resolves in a background or non-compositing
  tab. This is browser behaviour rather than something the viewer can work
  around, but it is worth knowing if you ever render a document off-screen.
  Verified working in a foreground tab: pages render, field overlays position
  correctly, and the adopted signature previews in place.
- Single-signer routing is implemented and tested; multi-signer routing order is
  enforced server-side but has not been exercised end to end.
#   D o c u S i g n A s s i g n m e n t  
 