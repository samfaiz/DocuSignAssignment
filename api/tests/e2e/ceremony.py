"""End-to-end signing ceremony against the running stack.

    python api/tests/e2e/ceremony.py

Drives the real HTTP API exactly as the SPA will: uploads a PDF, builds an
envelope, sends it, reads the signing link and the one-time passcode out of
Mailpit, signs, waits for the queue to seal, then verifies the result and
probes the access controls.
"""

import json
import mimetypes
import re
import sys
import time
import urllib.error
import urllib.request
import uuid as uuidlib
from pathlib import Path

API = "http://127.0.0.1:8000/api"
MAILPIT = "http://127.0.0.1:8025/api/v1"
SAMPLE_PDF = Path(__file__).resolve().parents[3] / "sign-service" / "tmp" / "1-original.pdf"

passed = 0
failed = 0
failures: list[str] = []


def check(label: str, ok: bool, detail: str = "") -> bool:
    global passed, failed
    if ok:
        passed += 1
        print(f"  PASS  {label}" + (f"  ({detail})" if detail else ""))
    else:
        failed += 1
        failures.append(label)
        print(f"  FAIL  {label}" + (f"  ({detail})" if detail else ""))
    return ok


def call(method, url, *, token=None, json_body=None, raw=False):
    headers = {"Accept": "application/json"}
    data = None
    if json_body is not None:
        data = json.dumps(json_body).encode()
        headers["Content-Type"] = "application/json"
    if token:
        headers["Authorization"] = f"Bearer {token}"

    request = urllib.request.Request(url, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=120) as response:
            body = response.read()
            return response.status, (body if raw else json.loads(body or b"{}"))
    except urllib.error.HTTPError as exc:
        body = exc.read()
        if raw:
            return exc.code, body
        try:
            return exc.code, json.loads(body or b"{}")
        except Exception:  # noqa: BLE001
            return exc.code, {"raw": body[:300].decode(errors="replace")}


def upload(url, token, path: Path):
    boundary = "----signdesk" + uuidlib.uuid4().hex
    mime = mimetypes.guess_type(path.name)[0] or "application/pdf"
    body = b"".join([
        f"--{boundary}\r\n".encode(),
        f'Content-Disposition: form-data; name="file"; filename="{path.name}"\r\n'.encode(),
        f"Content-Type: {mime}\r\n\r\n".encode(),
        path.read_bytes(),
        f"\r\n--{boundary}--\r\n".encode(),
    ])
    request = urllib.request.Request(url, data=body, method="POST", headers={
        "Accept": "application/json",
        "Authorization": f"Bearer {token}",
        "Content-Type": f"multipart/form-data; boundary={boundary}",
    })
    try:
        with urllib.request.urlopen(request, timeout=120) as response:
            return response.status, json.loads(response.read())
    except urllib.error.HTTPError as exc:
        return exc.code, json.loads(exc.read() or b"{}")


def mailpit_clear():
    request = urllib.request.Request(f"{MAILPIT}/messages", method="DELETE")
    try:
        urllib.request.urlopen(request, timeout=15).read()
    except Exception:  # noqa: BLE001
        pass


def mailpit_latest(subject_contains: str, attempts: int = 30) -> str:
    """Poll for a queued email; mail is dispatched through the worker."""
    for _ in range(attempts):
        try:
            with urllib.request.urlopen(f"{MAILPIT}/messages", timeout=15) as response:
                messages = json.loads(response.read()).get("messages", [])
            for message in messages:
                if subject_contains.lower() in (message.get("Subject") or "").lower():
                    with urllib.request.urlopen(
                        f"{MAILPIT}/message/{message['ID']}", timeout=15
                    ) as response:
                        full = json.loads(response.read())
                    return (full.get("Text") or "") + (full.get("HTML") or "")
        except Exception:  # noqa: BLE001
            pass
        time.sleep(2)
    return ""


print("=" * 70)
print("SignDesk end-to-end signing ceremony")
print("=" * 70)

if not SAMPLE_PDF.exists():
    print(f"\nMissing sample PDF at {SAMPLE_PDF}")
    print("Run: python sign-service/scripts/smoke_test.py")
    sys.exit(1)

mailpit_clear()

# --- 1. admin login -------------------------------------------------------
print("\n[1] Admin authentication")
status, body = call("POST", f"{API}/login", json_body={
    "email": "admin@signdesk.test", "password": "password",
})
check("logs in with correct credentials", status == 200 and "token" in body, str(status))
admin = body.get("token", "")

status, _ = call("POST", f"{API}/login", json_body={
    "email": "admin@signdesk.test", "password": "wrong-password",
})
check("rejects a wrong password", status == 422, str(status))

status, _ = call("GET", f"{API}/envelopes")
check("rejects an unauthenticated admin request", status == 401, str(status))

# --- 2. upload ------------------------------------------------------------
print("\n[2] Document upload")
status, body = call("GET", f"{API}/me", token=admin)
check("bearer token authenticates", status == 200)

status, body = upload(f"{API}/documents", admin, SAMPLE_PDF)
ok = check("uploads a PDF", status == 201, str(status))
document = body.get("document", {})
check("records the original SHA-256", len(document.get("sha256_original", "")) == 64)
check("counts the pages", document.get("page_count", 0) >= 1,
      f"{document.get('page_count')} page(s)")

# A file that merely claims to be a PDF must not survive the magic-byte check.
# Harmless bytes on purpose: a realistic webshell payload gets quarantined by
# Windows Defender between writing the file and reading it back, and what is
# under test here is the magic-byte check, which any non-PDF exercises.
fake = Path(__file__).resolve().parent / "not-really.pdf"
fake.write_bytes(b"GIF89a this file is not a PDF at all")
try:
    status, _ = upload(f"{API}/documents", admin, fake)
    check("rejects a non-PDF disguised as .pdf", status == 422, str(status))
finally:
    fake.unlink(missing_ok=True)

# --- 3. envelope ----------------------------------------------------------
print("\n[3] Envelope creation")
status, body = call("POST", f"{API}/envelopes", token=admin, json_body={
    "document_id": document.get("id"),
    "subject": "Consulting Services Agreement",
    "message": "Please review and sign at your convenience.",
    "expires_in_days": 30,
    "recipients": [{"name": "Faisal Khan", "email": "faisal@example.test"}],
    "fields": [
        {"recipient_index": 0, "type": "signature", "page": 0,
         "x": 0.085, "y": 0.815, "w": 0.26, "h": 0.055},
        {"recipient_index": 0, "type": "date", "page": 0,
         "x": 0.085, "y": 0.882, "w": 0.26, "h": 0.022},
    ],
})
check("creates a draft envelope", status == 201, str(status))
envelope = body.get("envelope", {})
env_uuid = envelope.get("uuid", "")
check("assigns an unguessable UUID", len(env_uuid) == 36, env_uuid)

status, _ = call("POST", f"{API}/envelopes", token=admin, json_body={
    "document_id": document.get("id"), "subject": "Bad",
    "recipients": [{"name": "A", "email": "a@b.test"}],
    "fields": [{"recipient_index": 0, "type": "signature", "page": 99,
                "x": 0.1, "y": 0.1, "w": 0.1, "h": 0.1}],
})
check("rejects a field on a page that does not exist", status == 422, str(status))

# --- 4. send --------------------------------------------------------------
print("\n[4] Sending")
status, _ = call("POST", f"{API}/envelopes/{env_uuid}/send", token=admin)
check("sends the envelope", status == 200, str(status))

email = mailpit_latest("Please sign")
match = re.search(r"/sign/([0-9a-f-]{36})\?t=([0-9a-f]{64})", email)
check("emails a signing link", match is not None)
if not match:
    print("\nCould not find a signing link in the invitation email; stopping.")
    sys.exit(1)

signer_token = match.group(2)
check("link carries a 256-bit token", len(signer_token) == 64)
SIGN = f"{API}/sign/{env_uuid}?t={signer_token}"

# --- 5. signer access controls -------------------------------------------
print("\n[5] Signer access control")
status, _ = call("GET", f"{API}/sign/{env_uuid}?t={'0' * 64}")
check("rejects a forged token", status == 404, str(status))

status, body = call("GET", SIGN)
check("accepts the real token", status == 200, str(status))
check("returns only this recipient's fields", len(body.get("fields", [])) == 2,
      f"{len(body.get('fields', []))} fields")
check("masks the signer's email", "*" in body.get("recipient", {}).get("email", ""),
      body.get("recipient", {}).get("email"))
check("starts unverified", body.get("recipient", {}).get("otp_verified") is False)

status, _ = call("POST", f"{SIGN}&x=1".replace("?t=", "/consent?t="),
                 json_body={"accepted": True})
check("blocks consent before OTP verification", status == 403, str(status))

status, _ = call("GET", f"{API}/sign/{env_uuid}/document?t={signer_token}", raw=True)
check("blocks document access before OTP verification", status == 403, str(status))

# --- 6. OTP ---------------------------------------------------------------
print("\n[6] Two-factor verification")
status, _ = call("POST", f"{API}/sign/{env_uuid}/otp?t={signer_token}", json_body={})
check("issues a passcode", status == 200, str(status))

otp_mail = mailpit_latest("verification code")
otp_match = re.search(r"\b(\d{6})\b", otp_mail)
check("emails a 6-digit passcode", otp_match is not None)
code = otp_match.group(1) if otp_match else "000000"

status, _ = call("POST", f"{API}/sign/{env_uuid}/otp/verify?t={signer_token}",
                 json_body={"code": "000000" if code != "000000" else "111111"})
check("rejects a wrong passcode", status == 422, str(status))

status, _ = call("POST", f"{API}/sign/{env_uuid}/otp/verify?t={signer_token}",
                 json_body={"code": code})
check("accepts the correct passcode", status == 200, str(status))

status, raw = call("GET", f"{API}/sign/{env_uuid}/document?t={signer_token}", raw=True)
check("serves the document once verified",
      status == 200 and raw[:5] == b"%PDF-", str(status))

# --- 7. consent -----------------------------------------------------------
print("\n[7] Consent and signing")
status, body = call("GET", SIGN)
disclosure = body.get("disclosure", {})
check("presents a versioned disclosure",
      bool(disclosure.get("version")) and len(disclosure.get("sha256", "")) == 64,
      disclosure.get("version"))

status, _ = call("POST", f"{API}/sign/{env_uuid}/fields?t={signer_token}",
                 json_body={"values": [{"field_id": body["fields"][0]["id"]}]})
check("blocks signing before consent", status == 403, str(status))

status, _ = call("POST", f"{API}/sign/{env_uuid}/consent?t={signer_token}",
                 json_body={"accepted": True})
check("records consent", status == 200, str(status))

# --- 8. signature adoption ------------------------------------------------
status, sig = call("POST", f"{API}/sign/{env_uuid}/signature?t={signer_token}",
                   json_body={"kind": "typed", "name": "Faisal Khan",
                              "font": "great-vibes"})
check("renders a typed signature", status == 201, str(status))
asset_id = sig.get("asset", {}).get("id")
check("hashes the signature artefact",
      len(sig.get("asset", {}).get("sha256", "")) == 64)

status, _ = call("POST", f"{API}/sign/{env_uuid}/signature?t={signer_token}",
                 json_body={"kind": "typed", "name": "X", "font": "comic-sans"})
check("rejects an unregistered font", status == 422, str(status))

status, body = call("GET", SIGN)
fields = body["fields"]
sig_field = next(f for f in fields if f["type"] == "signature")
date_field = next(f for f in fields if f["type"] == "date")

status, _ = call("POST", f"{API}/sign/{env_uuid}/fields?t={signer_token}", json_body={
    "values": [
        {"field_id": sig_field["id"], "asset_id": asset_id},
        {"field_id": date_field["id"], "text": "16 August 2026"},
    ],
})
check("saves field values", status == 200, str(status))

status, _ = call("POST", f"{API}/sign/{env_uuid}/fields?t={signer_token}", json_body={
    "values": [{"field_id": 999999, "text": "x"}],
})
check("rejects a field belonging to someone else (IDOR)", status == 403, str(status))

# --- 9. finish ------------------------------------------------------------
status, body = call("POST", f"{API}/sign/{env_uuid}/finish?t={signer_token}", json_body={})
check("completes signing", status == 200, str(status))
check("marks the envelope complete", body.get("envelope_complete") is True)

status, _ = call("GET", SIGN)
check("burns the token after signing", status in (404, 410), str(status))

# --- 10. sealing ----------------------------------------------------------
print("\n[10] Sealing (queued)")
sealed = None
for _ in range(60):
    status, body = call("GET", f"{API}/envelopes/{env_uuid}", token=admin)
    sealed = (body.get("envelope") or {}).get("sealed_document")
    if sealed:
        break
    time.sleep(2)

if check("seals the document", sealed is not None):
    check("reaches PAdES-B-LTA", sealed.get("pades_level") == "PAdES-B-LTA",
          sealed.get("pades_level"))
    check("uses a timestamp authority", bool(sealed.get("tsa_url")),
          sealed.get("tsa_url"))
    check("seals without degradation warnings", not sealed.get("warnings"),
          json.dumps(sealed.get("warnings")))
    check("appends the certificate of completion", sealed.get("page_count", 0) >= 2,
          f"{sealed.get('page_count')} pages")

    chain = body.get("audit_chain", {})
    check("audit chain verifies", chain.get("valid") is True,
          f"{chain.get('count')} events")

    status, pdf = call("GET", f"{API}/envelopes/{env_uuid}/download",
                       token=admin, raw=True)
    check("downloads the sealed PDF", status == 200 and pdf[:5] == b"%PDF-",
          f"{len(pdf):,} bytes")

    out = Path(__file__).resolve().parent / "sealed-e2e.pdf"
    out.write_bytes(pdf)
    print(f"        written to {out}")

    # Delivery is the last mile and fails quietly: a queued mailable carrying
    # binary content cannot be JSON-encoded, so the job dies during
    # serialisation and no copy ever goes out. Assert the mail actually lands.
    print("\n[11] Signed copy delivered")
    copy_mail = mailpit_latest("Signed:", attempts=30)
    check("emails the signed copy to the parties", copy_mail != "")
    check("names the signature standard in the email",
          "PAdES" in copy_mail, "PAdES-B-LTA" if "B-LTA" in copy_mail else "")

print(f"\n{'=' * 70}")
print(f"{passed} passed, {failed} failed")
if failures:
    for name in failures:
        print(f"  - {name}")
sys.exit(1 if failed else 0)
