"""Downloads the signature fonts used by the "type your name" mode.

All five are libre (SIL OFL or Apache 2.0), so they can ship with the product
rather than being loaded from a font CDN at signing time — which also keeps a
third-party request out of the signing ceremony.

    python scripts/fetch_fonts.py
"""

import sys
import urllib.request
from pathlib import Path

RAW = "https://raw.githubusercontent.com/google/fonts/main"

# Some of these are variable fonts upstream; Pillow renders their default
# instance, which is the regular weight we want. Saved under stable names so
# config.SIGNATURE_FONTS does not have to know which is which.
FONTS = {
    "GreatVibes-Regular.ttf": f"{RAW}/ofl/greatvibes/GreatVibes-Regular.ttf",
    "DancingScript-Regular.ttf": f"{RAW}/ofl/dancingscript/DancingScript%5Bwght%5D.ttf",
    "HomemadeApple-Regular.ttf": f"{RAW}/apache/homemadeapple/HomemadeApple-Regular.ttf",
    "Caveat-Regular.ttf": f"{RAW}/ofl/caveat/Caveat%5Bwght%5D.ttf",
    "Sacramento-Regular.ttf": f"{RAW}/ofl/sacramento/Sacramento-Regular.ttf",
}

dest = Path(__file__).resolve().parent.parent / "app" / "fonts"
dest.mkdir(parents=True, exist_ok=True)

failed = []
for filename, url in FONTS.items():
    target = dest / filename
    if target.exists() and target.stat().st_size > 1024:
        print(f"  = {filename} (already present)")
        continue
    try:
        with urllib.request.urlopen(url, timeout=30) as response:
            data = response.read()
        if len(data) < 1024:
            raise ValueError(f"suspiciously small response ({len(data)} bytes)")
        target.write_bytes(data)
        print(f"  + {filename} ({len(data) // 1024} KB)")
    except Exception as exc:  # noqa: BLE001
        print(f"  ! {filename}: {exc}", file=sys.stderr)
        failed.append(filename)

if failed:
    print(f"\n{len(failed)} font(s) could not be downloaded.", file=sys.stderr)
    print("Typed signatures will fall back to the fonts that did download.", file=sys.stderr)
    sys.exit(1)

print(f"\nAll fonts installed into {dest}")
