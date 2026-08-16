FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /srv

# Dependencies first so the layer caches across code changes.
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY scripts ./scripts

# Fonts are baked into the image rather than fetched at runtime — no network
# dependency in the signing path.
RUN python scripts/fetch_fonts.py || echo "font download failed; typed signatures degraded"

# Runs unprivileged. The PKI volume is mounted read-only.
RUN useradd --system --create-home --uid 10001 signdesk
USER signdesk

EXPOSE 8001

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD python -c "import urllib.request;urllib.request.urlopen('http://127.0.0.1:8001/health').read()"

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
