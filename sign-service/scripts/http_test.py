"""Exercises the HTTP surface, including the shared-secret HMAC.

    python scripts/http_test.py [base_url]

Assumes the service is already running.
"""

import base64
import hashlib
import hmac
import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8001"
SECRET = os.environ.get("SIGN_SERVICE_SECRET", "dev-shared-secret-change-me")

TMP = Path(__file__).resolve().parent.parent / "tmp"

passed = 0
failed = 0


def check(label: str, ok: bool, detail: str = "") -> None:
    global passed, failed
    if ok:
        passed += 1
        print(f"  PASS  {label}" + (f"  ({detail})" if detail else ""))
    else:
        failed += 1
        print(f"  FAIL  {label}" + (f"  ({detail})" if detail else ""))


def call(path: str, payload: dict | None = None, *, secret: str | None = None,
         method: str = "POST") -> tuple[int, dict]:
    if payload is None:
        request = urllib.request.Request(f"{BASE}{path}", method="GET")
    else:
        body = json.dumps(payload).encode()
        digest = hmac.new(
            (secret if secret is not None else SECRET).encode(), body, hashlib.sha256
        ).hexdigest()
        request = urllib.request.Request(
            f"{BASE}{path}", data=body, method=method,
            headers={
                "Content-Type": "application/json",
                "X-SignDesk-Signature": f"sha256={digest}",
            },
        )
    try:
        with urllib.request.urlopen(request, timeout=180) as response:
            return response.status, json.loads(response.read())
    except urllib.error.HTTPError as exc:
        raw = exc.read()
        try:
            return exc.code, json.loads(raw)
        except Exception:  # noqa: BLE001
            return exc.code, {"raw": raw[:200].decode(errors="replace")}


print(f"Testing {BASE}\n")

# --- health ---------------------------------------------------------------
print("health")
status, health = call("/health")
check("responds 200", status == 200)
check("signing certificate present", health.get("signing_certificate") is True)
check("CRL present", health.get("crl") is True)
check("all 5 fonts installed", len(health.get("fonts", [])) == 5,
      ", ".join(health.get("fonts", [])))

# --- auth -----------------------------------------------------------------
print("\nauthentication")
status, _ = call("/typed-signature", {"name": "Test"}, secret="wrong-secret")
check("rejects a bad HMAC with 401", status == 401)

body = json.dumps({"name": "Test"}).encode()
request = urllib.request.Request(
    f"{BASE}/typed-signature", data=body, method="POST",
    headers={"Content-Type": "application/json"},
)
try:
    urllib.request.urlopen(request, timeout=30)
    check("rejects a missing HMAC", False)
except urllib.error.HTTPError as exc:
    check("rejects a missing HMAC with 401", exc.code == 401, f"got {exc.code}")

# --- typed signature ------------------------------------------------------
print("\ntyped signature rendering")
status, typed = call("/typed-signature",
                     {"name": "Faisal Khan", "font": "great-vibes", "height": 160})
check("renders a PNG", status == 200 and "png_b64" in typed)
png = base64.b64decode(typed["png_b64"]) if status == 200 else b""
check("output is a real PNG", png[:8] == b"\x89PNG\r\n\x1a\n", f"{len(png):,} bytes")
check("reports a sha256 of the artefact", len(typed.get("sha256", "")) == 64)

status, _ = call("/typed-signature", {"name": "X", "font": "not-a-font"})
check("rejects an unknown font with 422", status == 422)

# --- upload sanitisation --------------------------------------------------
print("\nuploaded signature sanitisation")
status, cleaned = call("/sanitize-signature", {"image_b64": typed["png_b64"]})
check("accepts a valid image", status == 200 and "png_b64" in cleaned)

status, _ = call("/sanitize-signature",
                 {"image_b64": base64.b64encode(b"<?php system($_GET[0]); ?>").decode()})
check("rejects a non-image payload with 422", status == 422)

# --- finalize -------------------------------------------------------------
print("\nfinalize (stamp + certificate + seal)")
source = TMP / "1-original.pdf"
if not source.exists():
    print("  SKIP  run scripts/smoke_test.py first to produce tmp/1-original.pdf")
else:
    status, result = call("/finalize", {
        "pdf_b64": base64.b64encode(source.read_bytes()).decode(),
        "placements": [{
            "page": 0, "x": 0.085, "y": 0.815, "w": 0.26, "h": 0.055,
            "image_b64": typed["png_b64"],
        }],
        "certificate": {
            "envelope": {"id": "env_http_test", "subject": "HTTP test",
                         "status": "completed"},
            "document": {"filename": "test.pdf", "page_count": 1},
            "recipients": [{"name": "Faisal Khan", "email": "faisal@example.test",
                            "auth_method": "Email link + email OTP"}],
            "events": [{"seq": 1, "type": "recipient.signed", "actor": "Faisal Khan",
                        "occurred_at": "2026-08-16 09:19:38", "hash": "abc123"}],
        },
        "seal": {"level": "b-lta"},
    })
    check("responds 200", status == 200, result.get("detail", ""))
    if status == 200:
        check("reached PAdES-B-LTA", result.get("pades_level") == "PAdES-B-LTA",
              result.get("pades_level", ""))
        check("used a timestamp authority", bool(result.get("tsa_url")),
              result.get("tsa_url") or "none")
        check("reports both hashes",
              len(result.get("sha256_stamped", "")) == 64
              and len(result.get("sha256_sealed", "")) == 64)
        check("appended the certificate page", result.get("page_count", 0) >= 2,
              f"{result.get('page_count')} pages")
        check("reported no degradation warnings", not result.get("warnings"),
              "; ".join(result.get("warnings", [])))

        sealed = base64.b64decode(result["pdf_b64"])
        (TMP / "7-http-sealed.pdf").write_bytes(sealed)

        # --- verify -------------------------------------------------------
        print("\nverify")
        status, report = call("/verify",
                              {"pdf_b64": base64.b64encode(sealed).decode()})
        check("responds 200", status == 200)
        sigs = report.get("signatures", [])
        check("finds the seal and the document timestamp",
              report.get("signature_count") == 2, f"{report.get('signature_count')} found")
        check("every signature is intact",
              bool(sigs) and all(s.get("intact") for s in sigs))
        check("every signature is trusted",
              bool(sigs) and all(s.get("trusted") for s in sigs))
        check("reports incremental revisions", (report.get("revisions") or 0) >= 2,
              f"{report.get('revisions')} revisions")

        # --- tamper -------------------------------------------------------
        corrupted = bytearray(sealed)
        at = sealed.find(b"stream") + 60
        corrupted[at] ^= 0x20
        status, report = call("/verify",
                              {"pdf_b64": base64.b64encode(bytes(corrupted)).decode()})
        broken = status != 200 or not all(
            s.get("intact") for s in report.get("signatures", [{}])
        )
        check("detects a tampered document", broken)

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
