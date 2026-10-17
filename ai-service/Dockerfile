# syntax=docker/dockerfile:1.7
# ai-service: imagen multi-stage.
#  1. builder: uv instala las dependencias exactas de uv.lock en /app/.venv
#     (capa cacheada mientras no cambie el lock) y después el propio paquete.
#  2. runtime: solo Python + el entorno virtual; sin uv, sin compiladores,
#     usuario sin privilegios (~370 MB sin comprimir: langchain + SDK).

ARG PYTHON_VERSION=3.12

FROM python:${PYTHON_VERSION}-slim-bookworm AS builder
# uv desde PyPI (misma versión que el CI): sin depender de otro registro.
RUN pip install --no-cache-dir uv==0.11.32
ENV UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy \
    UV_PYTHON_DOWNLOADS=never \
    UV_PROJECT_ENVIRONMENT=/app/.venv
WORKDIR /app

# Dependencias primero (cambian poco): esta capa se reutiliza entre builds.
COPY pyproject.toml uv.lock ./
RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --locked --no-dev --no-install-project

# Después el código.
COPY README.md ./
COPY src ./src
RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --locked --no-dev --no-editable

FROM python:${PYTHON_VERSION}-slim-bookworm AS runtime
LABEL org.opencontainers.image.title="ai-service" \
      org.opencontainers.image.description="Cerebro de IA de AI Mail Automation Platform"

RUN groupadd --system --gid 10001 app \
 && useradd --system --uid 10001 --gid app --no-create-home --shell /usr/sbin/nologin app

COPY --from=builder --chown=app:app /app/.venv /app/.venv
ENV PATH="/app/.venv/bin:$PATH" \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    AI_SERVICE_ENVIRONMENT=production

USER app
WORKDIR /app
EXPOSE 8000

# Liveness para Docker (Kubernetes usa sus propias sondas sobre /health y /ready).
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD ["python", "-c", "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=3).status == 200 else 1)"]

# Un proceso: consumidor de eventos + /health, /ready, /metrics.
CMD ["ai-service-worker"]
