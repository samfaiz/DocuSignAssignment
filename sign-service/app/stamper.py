"""Composites signature artefacts onto PDF pages, and renders typed signatures.

Coordinates arrive normalised to 0..1 with a top-left origin, because that is
what the browser measures against a rendered pdf.js canvas. PDF user space has
a bottom-left origin, so every placement is flipped here — on the server, from
the stored field definition. The client never gets to say where ink lands in
the final document.
"""

import io
from dataclasses import dataclass

from PIL import Image, ImageDraw, ImageFont
from pypdf import PdfReader, PdfWriter
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen import canvas as rl_canvas

from .config import FONT_DIR, SIGNATURE_FONTS


@dataclass
class Placement:
    """One signature artefact bound to one rectangle on one page."""

    page: int  # 0-indexed
    x: float  # 0..1 from left edge
    y: float  # 0..1 from top edge
    w: float  # 0..1 of page width
    h: float  # 0..1 of page height
    image_png: bytes | None = None
    text: str | None = None
    font_size: float = 10.0


def render_typed_signature(
    name: str,
    font_key: str = "great-vibes",
    height_px: int = 160,
) -> bytes:
    """Render a typed name into a transparent PNG using a script font.

    Done on the server so the artefact embedded in the sealed PDF does not
    depend on which fonts the signer's browser happened to have installed —
    and so the same bytes are what we hash into the audit trail.
    """
    filename = SIGNATURE_FONTS.get(font_key)
    if filename is None:
        raise ValueError(f"unknown signature font: {font_key}")

    font_path = FONT_DIR / filename
    if not font_path.exists():
        raise FileNotFoundError(f"font not installed: {font_path}")

    font = ImageFont.truetype(str(font_path), size=height_px)

    # Measure first on a throwaway canvas, then draw at the measured size so
    # descenders and long names are never clipped.
    probe = ImageDraw.Draw(Image.new("RGBA", (1, 1)))
    left, top, right, bottom = probe.textbbox((0, 0), name, font=font)

    pad = height_px // 5
    width = max(1, right - left) + pad * 2
    height = max(1, bottom - top) + pad * 2

    img = Image.new("RGBA", (width, height), (255, 255, 255, 0))
    draw = ImageDraw.Draw(img)
    draw.text((pad - left, pad - top), name, font=font, fill=(15, 23, 42, 255))

    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()


def whiten_to_alpha(png_or_jpeg: bytes, threshold: int = 235) -> bytes:
    """Re-encode an uploaded signature image and knock its white background out.

    Doubles as the upload sanitiser: the image is fully decoded and re-encoded,
    so EXIF, colour profiles, trailing data and anything else smuggled in
    alongside the pixels does not survive into storage.
    """
    img = Image.open(io.BytesIO(png_or_jpeg))
    img = img.convert("RGBA")

    pixels = img.getdata()
    cleaned = [
        (r, g, b, 0) if (r >= threshold and g >= threshold and b >= threshold) else (r, g, b, a)
        for r, g, b, a in pixels
    ]
    img.putdata(cleaned)

    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()


def _overlay_for_page(
    width: float, height: float, placements: list[Placement]
) -> bytes:
    """Build a single-page PDF carrying just the artefacts for one page."""
    buf = io.BytesIO()
    c = rl_canvas.Canvas(buf, pagesize=(width, height))

    for p in placements:
        box_w = p.w * width
        box_h = p.h * height
        box_x = p.x * width
        # Flip the origin: normalised y is measured down from the top edge,
        # PDF y is measured up from the bottom.
        box_y = (1.0 - p.y - p.h) * height

        if p.image_png:
            img = ImageReader(io.BytesIO(p.image_png))
            iw, ih = img.getSize()

            # Fit inside the box without distorting the signature, and centre it.
            scale = min(box_w / iw, box_h / ih)
            draw_w, draw_h = iw * scale, ih * scale
            c.drawImage(
                img,
                box_x + (box_w - draw_w) / 2,
                box_y + (box_h - draw_h) / 2,
                width=draw_w,
                height=draw_h,
                mask="auto",  # honour the alpha channel
            )
        elif p.text:
            c.setFont("Helvetica", p.font_size)
            c.setFillColorRGB(0.06, 0.09, 0.16)
            c.drawString(box_x, box_y + (box_h - p.font_size) / 2, p.text)

    c.showPage()
    c.save()
    return buf.getvalue()


def stamp(pdf_bytes: bytes, placements: list[Placement]) -> bytes:
    """Merge every placement into the document and return the new PDF."""
    reader = PdfReader(io.BytesIO(pdf_bytes))
    writer = PdfWriter()

    by_page: dict[int, list[Placement]] = {}
    for p in placements:
        by_page.setdefault(p.page, []).append(p)

    for index, page in enumerate(reader.pages):
        page_placements = by_page.get(index)
        if page_placements:
            box = page.mediabox
            width = float(box.width)
            height = float(box.height)

            overlay_pdf = _overlay_for_page(width, height, page_placements)
            overlay_page = PdfReader(io.BytesIO(overlay_pdf)).pages[0]

            # The overlay is built in the page's own coordinate space, so if the
            # page declares a rotation the overlay must carry the same one or the
            # signature lands sideways.
            rotation = int(page.get("/Rotate", 0) or 0) % 360
            if rotation:
                overlay_page.rotate(rotation)

            page.merge_page(overlay_page)

        writer.add_page(page)

    out = io.BytesIO()
    writer.write(out)
    return out.getvalue()


def append_pages(base_pdf: bytes, extra_pdf: bytes) -> bytes:
    """Append every page of `extra_pdf` to `base_pdf`."""
    writer = PdfWriter()
    for source in (base_pdf, extra_pdf):
        for page in PdfReader(io.BytesIO(source)).pages:
            writer.add_page(page)

    out = io.BytesIO()
    writer.write(out)
    return out.getvalue()


def page_count(pdf_bytes: bytes) -> int:
    return len(PdfReader(io.BytesIO(pdf_bytes)).pages)
