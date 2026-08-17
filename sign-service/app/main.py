"""SignDesk sealing service.

Internal HTTP API. Every request is authenticated with a shared-secret HMAC and
the service is expected to run with no public ingress.

Payloads are JSON with base64 document bodies rather than multipart, so the
HMAC covers the exact bytes of the request with no boundary-parsing ambiguity.
"""

from __future__ import annotations

import base64
import hashlib
import io
import logging

from fastapi import Depends, FastAPI, HTTPException
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from . import certificate, config, sealer, stamper
from .auth import verify_hmac

logging.basicConfig(level=logging.INFO)
log = logging.getLogger("signdesk")

app = FastAPI(
    title="SignDesk sealing service",
    description="Stamps signature artefacts and applies PAdES signatures.",
    version="1.0.0",
)


# --------------------------------------------------------------------------
# Schemas
# --------------------------------------------------------------------------

class PlacementIn(BaseModel):
    page: int = Field(ge=0)
    x: float = Field(ge=0, le=1)
    y: float = Field(ge=0, le=1)
    w: float = Field(gt=0, le=1)
    h: float = Field(gt=0, le=1)
    image_b64: str | None = None
    text: str | None = None
    font_size: float = 10.0


class SealOptions(BaseModel):
    level: str = "b-lta"
    reason: str = "Electronically signed via SignDesk"
    location: str = ""
    contact: str = ""


class FinalizeIn(BaseModel):
    pdf_b64: str
    placements: list[PlacementIn] = []
    certificate: dict = {}
    seal: SealOptions = SealOptions()
    append_certificate: bool = True


class TypedSignatureIn(BaseModel):
    name: str = Field(min_length=1, max_length=120)
    font: str = "great-vibes"
    height: int = Field(default=160, ge=32, le=512)


class SanitizeIn(BaseModel):
    image_b64: str
    threshold: int = Field(default=235, ge=0, le=255)


class VerifyIn(BaseModel):
    pdf_b64: str


def _sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


# --------------------------------------------------------------------------
# Routes
# --------------------------------------------------------------------------

@app.get("/health")
def health() -> dict:
    """Unauthenticated readiness probe. Reports what the service can actually do."""
    fonts_present = sorted(
        key for key, filename in config.SIGNATURE_FONTS.items()
        if (config.FONT_DIR / filename).exists()
    )
    return {
        "status": "ok",
        "signing_certificate": config.signer_p12().exists(),
        "ca_certificate": config.ca_pem().exists(),
        "crl": config.crl_der().exists(),
        "tsa_url": config.TSA_URL,
        "fonts": fonts_present,
    }


@app.get("/fonts")
def fonts() -> dict:
    return {
        "fonts": [
            {"key": key, "available": (config.FONT_DIR / filename).exists()}
            for key, filename in config.SIGNATURE_FONTS.items()
        ]
    }


@app.post("/typed-signature", dependencies=[Depends(verify_hmac)])
def typed_signature(body: TypedSignatureIn) -> dict:
    """Render a typed name in a script font, server-side.

    Rendering here rather than in the browser means the artefact that ends up
    in the sealed PDF is the same artefact we hash into the audit trail, and it
    does not depend on the signer having the font installed.
    """
    try:
        png = stamper.render_typed_signature(body.name, body.font, body.height)
    except (ValueError, FileNotFoundError) as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc

    return {
        "png_b64": base64.b64encode(png).decode(),
        "sha256": _sha256(png),
        "font": body.font,
    }


@app.post("/sanitize-signature", dependencies=[Depends(verify_hmac)])
def sanitize_signature(body: SanitizeIn) -> dict:
    """Decode, strip and re-encode an uploaded signature image.

    Full decode/re-encode is the sanitisation: whatever was attached to the
    original file — EXIF, trailing bytes, a polyglot payload — does not survive
    being turned back into pixels and written out fresh.
    """
    try:
        raw = base64.b64decode(body.image_b64, validate=True)
        png = stamper.whiten_to_alpha(raw, body.threshold)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=422, detail=f"unreadable image: {exc}") from exc

    return {"png_b64": base64.b64encode(png).decode(), "sha256": _sha256(png)}


@app.post("/finalize", dependencies=[Depends(verify_hmac)])
def finalize(body: FinalizeIn) -> JSONResponse:
    """Stamp, append the certificate of completion, then seal.

    One call so the intermediate documents — which carry signature images but
    no cryptographic protection yet — never touch disk or cross the network.
    """
    try:
        pdf = base64.b64decode(body.pdf_b64, validate=True)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=422, detail="pdf_b64 is not valid base64") from exc

    # 1. Composite the visible signatures.
    placements = []
    for p in body.placements:
        image = base64.b64decode(p.image_b64) if p.image_b64 else None
        placements.append(
            stamper.Placement(
                page=p.page, x=p.x, y=p.y, w=p.w, h=p.h,
                image_png=image, text=p.text, font_size=p.font_size,
            )
        )

    try:
        stamped = stamper.stamp(pdf, placements) if placements else pdf
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=422, detail=f"could not stamp: {exc}") from exc

    sha256_stamped = _sha256(stamped)

    # 2. Append the human-readable evidence page.
    document = stamped
    if body.append_certificate:
        payload = dict(body.certificate)
        payload.setdefault("integrity", {})
        payload["integrity"]["sha256_stamped"] = sha256_stamped

        try:
            info = sealer.signer_info()
            payload["seal"] = {
                "pades_level": f"PAdES-{body.seal.level.upper()}",
                "md_algorithm": "SHA-256",
                "certificate_subject": info["subject"],
                "certificate_serial": info["serial"],
                "tsa_url": config.TSA_URL,
            }
        except Exception as exc:  # noqa: BLE001
            log.warning("could not read signer certificate for certificate page: %s", exc)

        try:
            document = stamper.append_pages(stamped, certificate.build(payload))
        except Exception as exc:  # noqa: BLE001
            raise HTTPException(
                status_code=500, detail=f"could not build certificate: {exc}"
            ) from exc

    # 3. Seal.
    try:
        result = sealer.seal(
            document,
            target_level=body.seal.level,
            reason=body.seal.reason,
            location=body.seal.location,
            contact=body.seal.contact,
        )
    except Exception as exc:  # noqa: BLE001
        log.exception("sealing failed")
        raise HTTPException(status_code=500, detail=f"sealing failed: {exc}") from exc

    for warning in result.warnings:
        log.warning("seal: %s", warning)

    return JSONResponse({
        "pdf_b64": base64.b64encode(result.pdf).decode(),
        "sha256_stamped": sha256_stamped,
        "sha256_sealed": _sha256(result.pdf),
        "pades_level": result.pades_level,
        "tsa_url": result.tsa_url,
        "certificate_subject": result.certificate_subject,
        "certificate_serial": result.certificate_serial,
        "page_count": stamper.page_count(result.pdf),
        "warnings": result.warnings,
    })


@app.post("/sanitize-photo", dependencies=[Depends(verify_hmac)])
def sanitize_photo(body: SanitizeIn) -> dict:
    """Strip metadata from a photograph captured during signing.

    Separate from /sanitize-signature: that one knocks a white background out
    to alpha, which is exactly wrong for a photograph. This one keeps the
    picture intact and removes everything around it — most importantly the EXIF
    GPS tags a phone camera writes without being asked.
    """
    try:
        raw = base64.b64decode(body.image_b64, validate=True)
        jpeg = stamper.sanitise_photo(raw)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=422, detail=f"unreadable image: {exc}") from exc

    return {"jpeg_b64": base64.b64encode(jpeg).decode(), "sha256": _sha256(jpeg)}


@app.post("/inspect", dependencies=[Depends(verify_hmac)])
def inspect_pdf(body: VerifyIn) -> dict:
    """Validate an uploaded PDF and report its shape.

    Doubles as upload validation: a file pypdf cannot parse is not a PDF we can
    stamp, whatever its extension or declared content type says. Encrypted
    documents are rejected here rather than failing later inside a queue job.
    """
    try:
        pdf = base64.b64decode(body.pdf_b64, validate=True)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=422, detail="pdf_b64 is not valid base64") from exc

    if not pdf.startswith(b"%PDF-"):
        raise HTTPException(status_code=422, detail="not a PDF (missing %PDF- header)")

    try:
        from pypdf import PdfReader

        reader = PdfReader(io.BytesIO(pdf))
        if reader.is_encrypted:
            raise HTTPException(
                status_code=422,
                detail="password-protected PDFs cannot be signed; remove the password first",
            )
        pages = len(reader.pages)
        if pages == 0:
            raise HTTPException(status_code=422, detail="PDF has no pages")

        first = reader.pages[0]
        box = first.mediabox
        sizes = {
            "width_pt": float(box.width),
            "height_pt": float(box.height),
            "rotation": int(first.get("/Rotate", 0) or 0) % 360,
        }
    except HTTPException:
        raise
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=422, detail=f"unreadable PDF: {exc}") from exc

    return {
        "page_count": pages,
        "size_bytes": len(pdf),
        "sha256": _sha256(pdf),
        "pdf_version": pdf[5:8].decode("ascii", errors="replace"),
        "first_page": sizes,
    }


@app.post("/verify", dependencies=[Depends(verify_hmac)])
def verify(body: VerifyIn) -> dict:
    """Read back the signatures on a sealed document."""
    try:
        pdf = base64.b64decode(body.pdf_b64, validate=True)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=422, detail="pdf_b64 is not valid base64") from exc

    try:
        report = sealer.inspect(pdf)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=422, detail=f"not a readable PDF: {exc}") from exc

    report["sha256"] = _sha256(pdf)
    return report
