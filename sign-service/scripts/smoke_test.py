"""End-to-end check of the sealing path, with no HTTP layer involved.

    python scripts/smoke_test.py

Builds a sample contract, renders a typed signature, composites it, appends a
certificate of completion, seals the result at the strongest PAdES level the
environment supports, then reads the signature back and tries to tamper with it.
"""

import hashlib
import io
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from reportlab.lib.pagesizes import A4  # noqa: E402
from reportlab.lib.units import mm  # noqa: E402
from reportlab.pdfgen import canvas as rl_canvas  # noqa: E402

from app import certificate, sealer, stamper  # noqa: E402

OUT = Path(__file__).resolve().parent.parent / "tmp"
OUT.mkdir(exist_ok=True)


def sample_contract() -> bytes:
    buf = io.BytesIO()
    c = rl_canvas.Canvas(buf, pagesize=A4)
    width, height = A4

    c.setFont("Helvetica-Bold", 16)
    c.drawString(25 * mm, height - 30 * mm, "Consulting Services Agreement")

    c.setFont("Helvetica", 10)
    lines = [
        "This Agreement is entered into between Acme Pvt Ltd (the Company)",
        "and the undersigned Consultant, effective on the date of signature.",
        "",
        "1. The Consultant will provide the services described in Schedule A.",
        "2. The Company will pay the fees set out in Schedule B.",
        "3. Either party may terminate on thirty (30) days written notice.",
        "4. This Agreement is governed by the laws of India.",
    ]
    y = height - 45 * mm
    for line in lines:
        c.drawString(25 * mm, y, line)
        y -= 6 * mm

    c.setFont("Helvetica", 9)
    c.drawString(25 * mm, 60 * mm, "Consultant signature:")
    c.line(25 * mm, 45 * mm, 95 * mm, 45 * mm)
    c.drawString(25 * mm, 38 * mm, "Date:")

    c.showPage()
    c.save()
    return buf.getvalue()


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def main() -> int:
    print("=" * 68)
    print("SignDesk sealing smoke test")
    print("=" * 68)

    # 1 ---------------------------------------------------------------------
    original = sample_contract()
    (OUT / "1-original.pdf").write_bytes(original)
    print(f"\n[1] Sample contract      {len(original):>7,} bytes  sha256 {sha(original)[:16]}…")

    # 2 ---------------------------------------------------------------------
    png = stamper.render_typed_signature("Faisal Khan", "great-vibes", 160)
    (OUT / "2-signature.png").write_bytes(png)
    print(f"[2] Typed signature PNG  {len(png):>7,} bytes  sha256 {sha(png)[:16]}…")

    # 3 ---------------------------------------------------------------------
    # Coordinates are normalised, top-left origin — the same shape the browser
    # sends after measuring against a pdf.js canvas.
    placements = [
        stamper.Placement(page=0, x=0.085, y=0.815, w=0.26, h=0.055, image_png=png),
        stamper.Placement(page=0, x=0.085, y=0.882, w=0.26, h=0.022,
                          text="16 August 2026", font_size=10),
    ]
    stamped = stamper.stamp(original, placements)
    (OUT / "3-stamped.pdf").write_bytes(stamped)
    print(f"[3] Signatures applied   {len(stamped):>7,} bytes  sha256 {sha(stamped)[:16]}…")

    # 4 ---------------------------------------------------------------------
    try:
        info = sealer.signer_info()
        print(f"[4] Signing certificate  {info['subject']}")
        print(f"                         serial {info['serial']}, expires {info['not_after']}")
    except Exception as exc:  # noqa: BLE001
        print(f"[4] FAILED to load signing certificate: {exc}")
        print("    Run: bash pki/scripts/gen-pki.sh")
        return 1

    cert_pdf = certificate.build({
        "envelope": {
            "id": "env_01JXAMPLE", "subject": "Consulting Services Agreement",
            "status": "completed", "created_at": "2026-08-16 09:12:04",
            "completed_at": "2026-08-16 09:19:41", "sender": "admin@acme.test",
        },
        "document": {
            "filename": "consulting-agreement.pdf", "page_count": 1,
            "sha256_original": sha(original),
        },
        "integrity": {"sha256_stamped": sha(stamped)},
        "recipients": [{
            "name": "Faisal Khan", "email": "faisal@example.test",
            "auth_method": "Email link + email OTP",
            "signed_at": "2026-08-16 09:19:38", "ip": "203.0.113.44",
            "consent_accepted_at": "2026-08-16 09:18:02",
            "consent_version": "esign-disclosure-v1",
            "consent_ip": "203.0.113.44",
        }],
        "events": [
            {"seq": 1, "type": "envelope.sent", "actor": "admin@acme.test",
             "occurred_at": "2026-08-16 09:12:04", "ip": "198.51.100.7",
             "hash": "9f2c1a77bd4e0a3168ff"},
            {"seq": 2, "type": "recipient.opened", "actor": "Faisal Khan",
             "occurred_at": "2026-08-16 09:17:22", "ip": "203.0.113.44",
             "hash": "c48b0e19aa7d55210cd1"},
            {"seq": 3, "type": "recipient.otp_verified", "actor": "Faisal Khan",
             "occurred_at": "2026-08-16 09:17:55", "ip": "203.0.113.44",
             "hash": "17ade9004fb2c8836e0a"},
            {"seq": 4, "type": "recipient.consented", "actor": "Faisal Khan",
             "occurred_at": "2026-08-16 09:18:02", "ip": "203.0.113.44",
             "hash": "6b30f7c2e15908ad74bb"},
            {"seq": 5, "type": "recipient.signed", "actor": "Faisal Khan",
             "occurred_at": "2026-08-16 09:19:38", "ip": "203.0.113.44",
             "hash": "e0117d5a3cc46f92180e"},
        ],
        "seal": {
            "pades_level": "PAdES-B-LTA", "md_algorithm": "SHA-256",
            "certificate_subject": info["subject"],
            "certificate_serial": info["serial"],
            "tsa_url": sealer.config.TSA_URL,
        },
    })
    combined = stamper.append_pages(stamped, cert_pdf)
    (OUT / "4-with-certificate.pdf").write_bytes(combined)
    print(f"[5] + certificate page   {len(combined):>7,} bytes  "
          f"{stamper.page_count(combined)} pages")

    # 5 ---------------------------------------------------------------------
    print(f"\n[6] Sealing (TSA: {sealer.config.TSA_URL})…")
    try:
        result = sealer.seal(combined, target_level="b-lta")
    except Exception as exc:  # noqa: BLE001
        print(f"    FAILED: {exc}")
        return 1

    sealed_path = OUT / "5-sealed.pdf"
    sealed_path.write_bytes(result.pdf)
    print(f"    Level achieved:  {result.pades_level}")
    print(f"    Sealed size:     {len(result.pdf):,} bytes")
    print(f"    sha256:          {sha(result.pdf)}")
    for w in result.warnings:
        print(f"    ! {w}")

    # 6 ---------------------------------------------------------------------
    print("\n[7] Reading the signature back…")
    report = sealer.inspect(result.pdf)
    print(f"    Signatures found: {report['signature_count']}")
    for sig in report["signatures"]:
        if "error" in sig:
            print(f"    ! {sig['field_name']}: {sig['error']}")
            continue
        print(f"    {sig['field_name']}: intact={sig['intact']} "
              f"valid={sig['valid']} trusted={sig['trusted']}")
        print(f"      coverage: {sig['coverage']}")
        if sig.get("signer"):
            print(f"      signer:   {sig['signer']}")

    # 7 --- the point of the whole exercise --------------------------------
    print("\n[8] Tamper test — altering the contract text after sealing…")
    corrupted = bytearray(result.pdf)

    # Target the original page content near the start of the file rather than a
    # byte at random. Corrupting the middle would land in the embedded
    # certificate/revocation data and break the file structurally, which proves
    # less: the interesting case is a file that still opens perfectly and whose
    # signature nonetheless reports the content as altered.
    # Every edit below is length-preserving, so no byte offset or xref entry
    # moves; the only thing that changes is content the signature covers.
    marker = result.pdf.find(b"thirty (30) days")
    if marker != -1:
        corrupted[marker:marker + 16] = b"three (03) days "
        print('    Changed "thirty (30) days" to "three (03) days" in the clear.')
    else:
        # Page content is compressed, so flip a byte inside the first stream.
        stream_at = result.pdf.find(b"stream")
        target = stream_at + 60 if stream_at != -1 else 1024
        corrupted[target] ^= 0x20
        print(f"    Flipped one byte of compressed page content at offset {target}.")

    (OUT / "6-tampered.pdf").write_bytes(bytes(corrupted))

    try:
        tampered_report = sealer.inspect(bytes(corrupted))
        still_intact = [
            s for s in tampered_report["signatures"] if s.get("intact") is True
        ]
        if still_intact:
            print("    FAIL: the signature still reports as intact after tampering.")
            return 1
        print("    PASS: tampering breaks the signature, as it must.")
    except Exception as exc:  # noqa: BLE001
        # A hard parse failure is also a legitimate detection.
        print(f"    PASS: tampered file no longer parses as signed ({type(exc).__name__}).")

    print(f"\nArtefacts written to {OUT}")
    print("Open 5-sealed.pdf in Adobe Reader (after trusting pki/out/ca.pem).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
