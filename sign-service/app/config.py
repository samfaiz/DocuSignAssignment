"""Runtime configuration for the sealing service."""

import os
from pathlib import Path

# Shared secret for the HMAC the API signs each request with. The service has
# no user-facing auth because it has no public ingress — it is reachable only
# from the API over the internal Docker network.
SIGN_SERVICE_SECRET: str = os.environ.get(
    "SIGN_SERVICE_SECRET", "dev-shared-secret-change-me"
)

# RFC 3161 timestamp authority. A trusted timestamp is what turns a B-B
# signature into B-T: it proves the document existed at time T according to an
# independent clock, rather than according to whatever the signing server's
# clock happened to say.
#   - http://timestamp.digicert.com  — free, chains to a widely trusted root
#   - https://freetsa.org/tsr        — free, needs its own root trusted
TSA_URL: str = os.environ.get("TSA_URL", "http://timestamp.digicert.com")

PKI_DIR: Path = Path(os.environ.get("PKI_DIR", "../pki/out"))
SIGNER_P12_PASSPHRASE: str = os.environ.get("SIGNER_P12_PASSPHRASE", "signdesk")

# Fonts offered for the "type your name" signature mode. All SIL Open Font
# License, so they can ship with the product.
SIGNATURE_FONTS: dict[str, str] = {
    "great-vibes": "GreatVibes-Regular.ttf",
    "dancing-script": "DancingScript-Regular.ttf",
    "homemade-apple": "HomemadeApple-Regular.ttf",
    "caveat": "Caveat-Regular.ttf",
    "sacramento": "Sacramento-Regular.ttf",
}

FONT_DIR: Path = Path(__file__).parent / "fonts"


def signer_p12() -> Path:
    return PKI_DIR / "signer.p12"


def ca_pem() -> Path:
    return PKI_DIR / "ca.pem"


def crl_der() -> Path:
    return PKI_DIR / "crl.der"
