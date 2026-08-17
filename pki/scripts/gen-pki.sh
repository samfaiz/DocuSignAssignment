#!/usr/bin/env bash
# Generates the development PKI: a root CA, a document-signing certificate,
# a PKCS#12 bundle for pyHanko, and a published CRL.
#
# Run from the repo root:  bash pki/scripts/gen-pki.sh
# Idempotent: pass --force to regenerate from scratch.

set -euo pipefail

cd "$(dirname "$0")/.."   # -> pki/

# Some Windows installs (PostgreSQL/psqlODBC, Git) export OPENSSL_CONF pointing
# at a file that does not exist, which breaks every openssl call that does not
# pass -config explicitly. Pin it to ours.
export OPENSSL_CONF="$PWD/openssl.cnf"

# Certificate identity. These end up in the subject line a counterparty reads
# in Adobe Reader and on the certificate of completion, so a deployment should
# set them rather than shipping the defaults.
#
#   PKI_ORG=".." PKI_CRL_URL="https://your.domain/crl.der" bash pki/scripts/gen-pki.sh --force
#
export PKI_COUNTRY="${PKI_COUNTRY:-IN}"
export PKI_ORG="${PKI_ORG:-SignDesk}"
export PKI_CA_CN="${PKI_CA_CN:-SignDesk Signing CA}"
export PKI_SIGNER_CN="${PKI_SIGNER_CN:-SignDesk Document Signer}"
export PKI_SIGNER_EMAIL="${PKI_SIGNER_EMAIL:-signer@signdesk.local}"

# Must be reachable by anyone verifying the document, not just by this machine.
export PKI_CRL_URL="${PKI_CRL_URL:-http://localhost:8080/crl.der}"

OUT=out
P12_PASS="${SIGNER_P12_PASSPHRASE:-signdesk}"

if [[ "${1:-}" == "--force" ]]; then
  rm -rf "$OUT"
fi

if [[ -f "$OUT/signer.p12" ]]; then
  echo "PKI already present in pki/$OUT (use --force to regenerate)."
  exit 0
fi

mkdir -p "$OUT/newcerts"
: > "$OUT/index.txt"
echo 1000 > "$OUT/serial"
echo 1000 > "$OUT/crlnumber"

echo "==> Root CA"
openssl req -x509 -newkey rsa:4096 -sha256 -days 3650 -nodes \
  -config openssl.cnf \
  -keyout "$OUT/ca.key" -out "$OUT/ca.pem"

echo "==> Document-signing key + CSR"
openssl req -new -newkey rsa:2048 -sha256 -nodes \
  -config openssl.cnf -section req_signer \
  -keyout "$OUT/signer.key" -out "$OUT/signer.csr"

echo "==> Issuing signing certificate (recorded in the CA database)"
openssl ca -batch -config openssl.cnf \
  -extensions v3_signer -days 825 -notext -md sha256 \
  -in "$OUT/signer.csr" -out "$OUT/signer.pem"

echo "==> PKCS#12 bundle for pyHanko"
openssl pkcs12 -export \
  -inkey "$OUT/signer.key" -in "$OUT/signer.pem" -certfile "$OUT/ca.pem" \
  -name "SignDesk Document Signer" \
  -out "$OUT/signer.p12" -passout "pass:$P12_PASS"

echo "==> Certificate chain"
cat "$OUT/signer.pem" "$OUT/ca.pem" > "$OUT/chain.pem"

echo "==> CRL (PEM + DER; DER is what the distribution point serves)"
openssl ca -batch -config openssl.cnf -gencrl -out "$OUT/crl.pem"
openssl crl -in "$OUT/crl.pem" -outform DER -out "$OUT/crl.der"

echo
echo "PKI ready in pki/$OUT:"
ls -1 "$OUT" | sed 's/^/    /'
echo
echo "Signing certificate:"
openssl x509 -in "$OUT/signer.pem" -noout -subject -issuer -dates \
  -ext keyUsage,extendedKeyUsage,crlDistributionPoints | sed 's/^/    /'
echo
echo "Trust pki/$OUT/ca.pem in Adobe Acrobat Reader to see this demo validate:"
echo "    Edit > Preferences > Signatures > Identities & Trusted Certificates"
