"""Creates and sends a fresh envelope, then prints the signer URL.

    python api/tests/e2e/make_link.py

Handy for exercising the signer portal by hand without walking the admin UI.
Self-contained on purpose: importing ceremony.py would run its whole suite.
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
SPA = "http://localhost:5173"
SAMPLE_PDF = Path(__file__).resolve().parents[3] / "sign-service" / "tmp" / "1-original.pdf"


def call(method, url, *, token=None, json_body=None):
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
            return response.status, json.loads(response.read() or b"{}")
    except urllib.error.HTTPError as exc:
        return exc.code, json.loads(exc.read() or b"{}")


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


def mailpit_latest(subject_contains: str, attempts: int = 30) -> str:
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


if not SAMPLE_PDF.exists():
    sys.exit(f"missing sample PDF at {SAMPLE_PDF} — run sign-service/scripts/smoke_test.py")

try:
    urllib.request.urlopen(
        urllib.request.Request(f"{MAILPIT}/messages", method="DELETE"), timeout=15
    ).read()
except Exception:  # noqa: BLE001
    pass

status, body = call("POST", f"{API}/login", json_body={
    "email": "admin@signdesk.test", "password": "password",
})
if status != 200:
    sys.exit(f"login failed: {status} {body}")
admin = body["token"]

status, body = upload(f"{API}/documents", admin, SAMPLE_PDF)
if status != 201:
    sys.exit(f"upload failed: {status} {body}")
document = body["document"]

status, body = call("POST", f"{API}/envelopes", token=admin, json_body={
    "document_id": document["id"],
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
if status != 201:
    sys.exit(f"create failed: {status} {body}")
uuid = body["envelope"]["uuid"]

status, body = call("POST", f"{API}/envelopes/{uuid}/send", token=admin)
if status != 200:
    sys.exit(f"send failed: {status} {body}")

email = mailpit_latest("Please sign")
match = re.search(r"/sign/([0-9a-f-]{36})\?t=([0-9a-f]{64})", email)
if not match:
    sys.exit("no signing link found in the invitation email")

print(f"{SPA}/sign/{match.group(1)}?t={match.group(2)}")
