"""PAdES sealing via pyHanko.

The signature applied here is *invisible*. The visible ink — drawn, uploaded or
typed — is already page content by the time we get here, exactly as it works in
Acrobat and DocuSign: the marks a human sees and the cryptography that protects
them are two different things. What this module adds is one document-level
signature covering every byte of the file.

Level ladder, weakest to strongest:
    B-B    PKCS#7 over the ByteRange. Proves integrity + who holds the key.
    B-T    + RFC 3161 timestamp. Proves *when*, on someone else's clock.
    B-LT   + certificate chain and revocation data embedded in a DSS.
           Still verifiable once the CA's OCSP/CRL endpoints are gone.
    B-LTA  + a document timestamp over all of that, renewable before its own
           crypto ages out. Verifiable decades later.

We ask for B-LTA and degrade honestly if the network or the PKI cannot support
it, reporting the level actually achieved rather than claiming the target.
"""

from __future__ import annotations

import io
import logging
from dataclasses import dataclass
from pathlib import Path

from asn1crypto import crl as asn1_crl
from asn1crypto import pem
from asn1crypto import x509 as asn1_x509
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.sign import signers, timestamps
from pyhanko.sign.fields import SigSeedSubFilter
from pyhanko_certvalidator import ValidationContext

from . import config

log = logging.getLogger("signdesk.sealer")

# Ordered strongest-first; seal() walks down this list on failure.
LEVELS = ("b-lta", "b-lt", "b-t", "b-b")


@dataclass
class SealResult:
    pdf: bytes
    pades_level: str
    tsa_url: str | None
    certificate_subject: str
    certificate_serial: str
    warnings: list[str]


def _load_der(path: Path) -> bytes:
    data = path.read_bytes()
    if pem.detect(data):
        _, _, der = pem.unarmor(data)
        return der
    return data


def load_ca_certificate() -> asn1_x509.Certificate:
    return asn1_x509.Certificate.load(_load_der(config.ca_pem()))


def load_crl() -> asn1_crl.CertificateList | None:
    path = config.crl_der()
    if not path.exists():
        return None
    return asn1_crl.CertificateList.load(_load_der(path))


def build_validation_context(allow_fetching: bool = True) -> ValidationContext:
    """Trust anchors and revocation data for embedding into the signature.

    `extra_trust_roots` *adds* our development CA to the system trust list
    rather than replacing it. That distinction matters: the timestamp token
    chains to a public CA, so replacing the trust list would make B-T fail.

    The CRL is passed in directly instead of being fetched. The distribution
    point in the certificate is there for Adobe Reader on the host; inside the
    container there is no reason to make a network round trip for a file we
    already have mounted.
    """
    crls = []
    crl = load_crl()
    if crl is not None:
        crls.append(crl)

    return ValidationContext(
        extra_trust_roots=[load_ca_certificate()],
        crls=crls,
        allow_fetching=allow_fetching,
    )


def _signer() -> signers.SimpleSigner:
    p12 = config.signer_p12()
    if not p12.exists():
        raise FileNotFoundError(
            f"signing certificate missing at {p12} — run pki/scripts/gen-pki.sh"
        )
    signer = signers.SimpleSigner.load_pkcs12(
        pfx_file=str(p12),
        passphrase=config.SIGNER_P12_PASSPHRASE.encode("utf-8"),
    )
    if signer is None:
        raise ValueError("could not load signer.p12 (wrong passphrase?)")
    return signer


def signer_info() -> dict:
    """Subject and serial of the signing certificate, for the certificate page."""
    cert = _signer().signing_cert
    return {
        "subject": cert.subject.human_friendly,
        "serial": format(cert.serial_number, "x"),
        "not_before": str(cert["tbs_certificate"]["validity"]["not_before"].native),
        "not_after": str(cert["tbs_certificate"]["validity"]["not_after"].native),
    }


def _metadata_for(level: str, reason: str, location: str, contact: str,
                  field_name: str) -> signers.PdfSignatureMetadata:
    kwargs: dict = dict(
        field_name=field_name,
        md_algorithm="sha256",
        reason=reason or None,
        location=location or None,
        contact_info=contact or None,
    )

    if level == "b-b":
        # No PAdES subfilter: a plain CMS detached signature.
        kwargs["subfilter"] = SigSeedSubFilter.ADOBE_PKCS7_DETACHED
        return signers.PdfSignatureMetadata(**kwargs)

    kwargs["subfilter"] = SigSeedSubFilter.PADES

    if level in ("b-lt", "b-lta"):
        kwargs["validation_context"] = build_validation_context()
        kwargs["embed_validation_info"] = True
    if level == "b-lta":
        kwargs["use_pades_lta"] = True

    return signers.PdfSignatureMetadata(**kwargs)


def _attempt(pdf_bytes: bytes, level: str, reason: str, location: str,
             contact: str, field_name: str,
             signer: signers.SimpleSigner) -> bytes:
    # A fresh IncrementalPdfFileWriter per attempt — a failed attempt may have
    # already mutated the writer's view of the document.
    writer = IncrementalPdfFileWriter(io.BytesIO(pdf_bytes))

    timestamper = None
    if level != "b-b":
        timestamper = timestamps.HTTPTimeStamper(url=config.TSA_URL)

    out = io.BytesIO()
    signers.sign_pdf(
        writer,
        signature_meta=_metadata_for(level, reason, location, contact, field_name),
        signer=signer,
        timestamper=timestamper,
        output=out,
    )
    return out.getvalue()


def seal(
    pdf_bytes: bytes,
    target_level: str = "b-lta",
    reason: str = "Electronically signed via SignDesk",
    location: str = "",
    contact: str = "",
    field_name: str = "SignDeskSeal",
) -> SealResult:
    """Seal the document, degrading gracefully from the requested level."""
    if target_level not in LEVELS:
        raise ValueError(f"unknown PAdES level: {target_level}")

    signer = _signer()
    cert = signer.signing_cert
    subject = cert.subject.human_friendly
    serial = format(cert.serial_number, "x")

    warnings: list[str] = []
    ladder = LEVELS[LEVELS.index(target_level):]

    for level in ladder:
        try:
            sealed = _attempt(
                pdf_bytes, level, reason, location, contact, field_name, signer
            )
        except Exception as exc:  # noqa: BLE001 — we genuinely want any failure
            log.warning("PAdES %s failed: %s", level.upper(), exc)
            warnings.append(f"{level.upper()} unavailable: {exc}")
            continue

        if level != target_level:
            warnings.append(
                f"degraded from {target_level.upper()} to {level.upper()}"
            )

        return SealResult(
            pdf=sealed,
            pades_level=f"PAdES-{level.upper()}",
            tsa_url=None if level == "b-b" else config.TSA_URL,
            certificate_subject=subject,
            certificate_serial=serial,
            warnings=warnings,
        )

    raise RuntimeError(
        "could not seal the document at any PAdES level: " + "; ".join(warnings)
    )


def inspect(pdf_bytes: bytes) -> dict:
    """Read back the signatures in a sealed file.

    Reports integrity (do the bytes still match the signature?) separately from
    trust (does the certificate chain to something we trust?), because those
    fail for very different reasons and a useful verification page has to say
    which one went wrong.
    """
    from pyhanko.pdf_utils.reader import PdfFileReader
    from pyhanko.sign.validation import validate_pdf_signature, validate_pdf_timestamp

    reader = PdfFileReader(io.BytesIO(pdf_bytes))
    vc = build_validation_context()

    def describe(embedded, is_timestamp: bool) -> dict:
        entry: dict = {
            "field_name": embedded.field_name,
            "kind": "document_timestamp" if is_timestamp else "signature",
        }
        try:
            # A document timestamp is a /DocTimeStamp, not a /Sig, and has to go
            # through the timestamp validator. Its presence alongside a PAdES
            # signature is precisely what distinguishes B-LTA from B-LT.
            status = (
                validate_pdf_timestamp(embedded, vc)
                if is_timestamp
                else validate_pdf_signature(embedded, vc)
            )
            signing_cert = getattr(status, "signing_cert", None)
            entry.update(
                intact=bool(getattr(status, "intact", False)),
                valid=bool(getattr(status, "valid", False)),
                trusted=bool(getattr(status, "trusted", False)),
                coverage=str(getattr(status, "coverage", "")),
                signer=signing_cert.subject.human_friendly if signing_cert else None,
                timestamp=str(getattr(status, "timestamp", "") or ""),
            )
        except Exception as exc:  # noqa: BLE001
            entry["error"] = str(exc)
        return entry

    timestamp_fields = {
        s.field_name for s in reader.embedded_timestamp_signatures
    }
    signatures = [
        describe(embedded, embedded.field_name in timestamp_fields)
        for embedded in reader.embedded_signatures
    ]

    return {
        "signature_count": len(signatures),
        "signatures": signatures,
        # Every signature is written as an incremental update, so the revision
        # count is also the number of times this file was appended to — and
        # every earlier revision is still recoverable from the same bytes.
        "revisions": reader.total_revisions,
    }
