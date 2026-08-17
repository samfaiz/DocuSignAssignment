"""Renders the Certificate of Completion.

This page is the human-readable form of the evidence package. In a dispute it
is the artefact that actually gets read, so it has to state not just *that*
someone signed but how they were identified, what they consented to, and what
the document hashed to before and after.
"""

import io
from typing import Any

from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import (
    KeepTogether,
    PageBreak,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)

INK = colors.HexColor("#0f172a")
MUTED = colors.HexColor("#64748b")
RULE = colors.HexColor("#cbd5e1")
BAND = colors.HexColor("#f1f5f9")


def _styles() -> dict[str, ParagraphStyle]:
    base = getSampleStyleSheet()
    return {
        "title": ParagraphStyle(
            "title", parent=base["Title"], fontName="Helvetica-Bold",
            fontSize=17, leading=21, textColor=INK, alignment=TA_LEFT,
            spaceAfter=2,
        ),
        "subtitle": ParagraphStyle(
            "subtitle", parent=base["Normal"], fontName="Helvetica",
            fontSize=9, leading=12, textColor=MUTED, spaceAfter=14,
        ),
        "h2": ParagraphStyle(
            "h2", parent=base["Heading2"], fontName="Helvetica-Bold",
            fontSize=10.5, leading=13, textColor=INK,
            spaceBefore=13, spaceAfter=5,
        ),
        "body": ParagraphStyle(
            "body", parent=base["Normal"], fontName="Helvetica",
            fontSize=8.5, leading=11.5, textColor=INK,
        ),
        "mono": ParagraphStyle(
            "mono", parent=base["Normal"], fontName="Courier",
            fontSize=7, leading=9.5, textColor=INK,
        ),
        "note": ParagraphStyle(
            "note", parent=base["Normal"], fontName="Helvetica-Oblique",
            fontSize=7.5, leading=10, textColor=MUTED, spaceBefore=8,
        ),
    }


def _kv_table(rows: list[tuple[str, str]], st: dict, width: float) -> Table:
    data = [
        [Paragraph(f"<b>{k}</b>", st["body"]),
         Paragraph(v, st["mono"] if len(v) > 48 and " " not in v.strip() else st["body"])]
        for k, v in rows
    ]
    t = Table(data, colWidths=[width * 0.28, width * 0.72], hAlign="LEFT")
    t.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("TOPPADDING", (0, 0), (-1, -1), 3),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
        ("LEFTPADDING", (0, 0), (-1, -1), 0),
        ("LINEBELOW", (0, 0), (-1, -2), 0.25, RULE),
    ]))
    return t


def _grid_table(header: list[str], rows: list[list[str]], st: dict,
                widths: list[float]) -> Table:
    data = [[Paragraph(f"<b>{h}</b>", st["body"]) for h in header]]
    data += [[Paragraph(str(c), st["body"]) for c in r] for r in rows]

    t = Table(data, colWidths=widths, hAlign="LEFT", repeatRows=1)
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), BAND),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
        ("LEFTPADDING", (0, 0), (-1, -1), 5),
        ("RIGHTPADDING", (0, 0), (-1, -1), 5),
        ("GRID", (0, 0), (-1, -1), 0.25, RULE),
    ]))
    return t


def build(payload: dict[str, Any]) -> bytes:
    """Render the certificate. `payload` mirrors the API's audit projection."""
    st = _styles()
    buf = io.BytesIO()

    doc = SimpleDocTemplate(
        buf, pagesize=A4,
        leftMargin=18 * mm, rightMargin=18 * mm,
        topMargin=16 * mm, bottomMargin=16 * mm,
        title="Certificate of Completion",
        author="SignDesk",
    )
    width = doc.width

    envelope = payload.get("envelope", {}) or {}
    document = payload.get("document", {}) or {}
    recipients = payload.get("recipients", []) or []
    events = payload.get("events", []) or []
    integrity = payload.get("integrity", {}) or {}

    story: list[Any] = [
        Paragraph("Certificate of Completion", st["title"]),
        Paragraph(
            "Evidence record for the electronic signature ceremony described below. "
            "All times are UTC.",
            st["subtitle"],
        ),
    ]

    # --- Envelope -----------------------------------------------------------
    story.append(Paragraph("Envelope", st["h2"]))
    story.append(_kv_table([
        ("Envelope ID", str(envelope.get("id", "—"))),
        ("Subject", str(envelope.get("subject", "—"))),
        ("Status", str(envelope.get("status", "—"))),
        ("Created", str(envelope.get("created_at", "—"))),
        ("Completed", str(envelope.get("completed_at", "—"))),
        ("Sender", str(envelope.get("sender", "—"))),
    ], st, width))

    # --- Document integrity -------------------------------------------------
    story.append(Paragraph("Document integrity", st["h2"]))
    story.append(_kv_table([
        ("Filename", str(document.get("filename", "—"))),
        ("Pages", str(document.get("page_count", "—"))),
        ("SHA-256 (as uploaded)", str(document.get("sha256_original", "—"))),
        ("SHA-256 (after signatures applied)", str(integrity.get("sha256_stamped", "—"))),
    ], st, width))
    story.append(Paragraph(
        "The sealed file carries a PAdES digital signature over its own bytes. Any "
        "later modification breaks that signature and is reported by any compliant "
        "PDF reader. The hash of the file as originally uploaded is recorded above so "
        "the starting point is provable too.",
        st["note"],
    ))

    # --- Signers ------------------------------------------------------------
    story.append(Paragraph("Signers and identity verification", st["h2"]))
    story.append(_grid_table(
        ["Name", "Email", "Verified by", "Signed at (UTC)", "IP"],
        [[
            r.get("name", "—"), r.get("email", "—"),
            r.get("auth_method", "—"), r.get("signed_at", "—"), r.get("ip", "—"),
        ] for r in recipients] or [["—", "—", "—", "—", "—"]],
        st,
        [width * 0.21, width * 0.27, width * 0.19, width * 0.21, width * 0.12],
    ))

    # --- Location -----------------------------------------------------------
    if any(r.get("location") for r in recipients):
        story.append(Paragraph("Location reported by signer", st["h2"]))
        story.append(_grid_table(
            ["Signer", "Location"],
            [[r.get("name", "—"), r.get("location", "Not requested")]
             for r in recipients],
            st,
            [width * 0.30, width * 0.70],
        ))
        story.append(Paragraph(
            "Location is optional and was supplied by the signer's browser after "
            "they granted permission. Unlike the IP address above, which the server "
            "observed, these coordinates are self-reported and can be altered by a "
            "determined signer — they corroborate the rest of the record rather "
            "than standing on their own.",
            st["note"],
        ))

    # --- Consent ------------------------------------------------------------
    consents = [r for r in recipients if r.get("consent_accepted_at")]
    if consents:
        story.append(Paragraph("Consent to transact electronically", st["h2"]))
        story.append(_grid_table(
            ["Signer", "Disclosure version", "Accepted at (UTC)", "IP"],
            [[
                c.get("name", "—"), c.get("consent_version", "—"),
                c.get("consent_accepted_at", "—"), c.get("consent_ip", "—"),
            ] for c in consents],
            st,
            [width * 0.30, width * 0.24, width * 0.28, width * 0.18],
        ))

    # --- Audit trail --------------------------------------------------------
    story.append(PageBreak())
    story.append(Paragraph("Audit trail", st["h2"]))
    story.append(Paragraph(
        "Every entry is chained to the one before it: each row's hash covers the "
        "previous hash, so removing, reordering or editing any single event "
        "invalidates every hash that follows.",
        st["note"],
    ))
    story.append(Spacer(1, 6))
    story.append(_grid_table(
        ["#", "Event", "Actor", "Timestamp (UTC)", "IP", "Hash (first 16)"],
        [[
            e.get("seq", ""), e.get("type", ""), e.get("actor", "—"),
            e.get("occurred_at", ""), e.get("ip", "—"),
            str(e.get("hash", ""))[:16],
        ] for e in events] or [["—"] * 6],
        st,
        [width * 0.05, width * 0.26, width * 0.20,
         width * 0.22, width * 0.12, width * 0.15],
    ))

    # --- Cryptographic seal -------------------------------------------------
    seal = payload.get("seal", {}) or {}
    story.append(Spacer(1, 10))
    story.append(KeepTogether([
        Paragraph("Cryptographic seal", st["h2"]),
        _kv_table([
            ("Signature standard", str(seal.get("pades_level", "PAdES-B-LTA"))),
            ("Digest algorithm", str(seal.get("md_algorithm", "SHA-256"))),
            ("Signing certificate", str(seal.get("certificate_subject", "—"))),
            ("Certificate serial", str(seal.get("certificate_serial", "—"))),
            ("Timestamp authority", str(seal.get("tsa_url", "—"))),
        ], st, width),
        Paragraph(
            "PAdES-B-LTA embeds an RFC 3161 timestamp from an independent authority, "
            "the revocation data needed to check the certificate chain, and a renewable "
            "chain of document timestamps — so the signature stays verifiable after the "
            "signing certificate itself has expired.",
            st["note"],
        ),
    ]))

    doc.build(story)
    return buf.getvalue()
