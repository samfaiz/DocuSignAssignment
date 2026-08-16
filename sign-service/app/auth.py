"""Shared-secret request authentication.

The API signs the raw request body with HMAC-SHA256 and sends the digest in
`X-SignDesk-Signature`. This is not a substitute for network isolation — the
service should have no public ingress — it is the second layer.
"""

import hashlib
import hmac

from fastapi import Header, HTTPException, Request

from .config import SIGN_SERVICE_SECRET


def expected_digest(body: bytes) -> str:
    return hmac.new(
        SIGN_SERVICE_SECRET.encode("utf-8"), body, hashlib.sha256
    ).hexdigest()


async def verify_hmac(
    request: Request,
    x_signdesk_signature: str = Header(default=""),
) -> None:
    """FastAPI dependency: reject any request not signed with the shared secret."""
    body = await request.body()
    provided = x_signdesk_signature.removeprefix("sha256=")

    # compare_digest, not ==, so a wrong signature cannot be recovered by
    # timing how long the comparison took.
    if not provided or not hmac.compare_digest(provided, expected_digest(body)):
        raise HTTPException(status_code=401, detail="invalid request signature")
