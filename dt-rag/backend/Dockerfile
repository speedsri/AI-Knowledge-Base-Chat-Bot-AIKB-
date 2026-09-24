FROM python:3.12-slim

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential curl && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY ingestion ./ingestion

# Pre-warm tiktoken's cl100k_base encoding at build time (requires network
# access during the image build) so container startup never depends on
# reaching openaipublic.blob.core.windows.net at runtime. If this download
# fails or is unreachable in a given environment, app/chunking.py falls back
# to an approximate character-based chunker rather than crashing -- but this
# build step means that fallback should never actually be needed in practice.
RUN python -c "import tiktoken; tiktoken.get_encoding('cl100k_base')" || true

EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD curl -f http://localhost:8000/health || exit 1
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1"]
