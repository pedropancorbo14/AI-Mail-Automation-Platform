import io
import json
import logging
from collections.abc import Iterator

import pytest

from ai_service.infrastructure.observability.logging import (
    bind_context,
    clear_context,
    configure_logging,
    get_logger,
)


@pytest.fixture
def stream() -> Iterator[io.StringIO]:
    buffer = io.StringIO()
    configure_logging("DEBUG", stream=buffer)
    yield buffer
    clear_context()


def lines(stream: io.StringIO) -> list[dict[str, object]]:
    return [json.loads(line) for line in stream.getvalue().splitlines()]


def test_writes_one_json_object_per_line(stream: io.StringIO) -> None:
    get_logger("tests").warning("email_skipped", reason="automatic")

    [entry] = lines(stream)
    assert entry["message"] == "email_skipped"
    assert entry["level"] == "WARNING"  # como level_name de Laravel: mismos filtros en Grafana
    assert entry["logger"] == "tests"
    assert entry["service"] == "ai-service"
    assert entry["reason"] == "automatic"
    assert str(entry["timestamp"]).endswith("Z")


def test_bound_context_appears_until_cleared(stream: io.StringIO) -> None:
    bind_context(trace_id="abc123", tenant_id=7, email_id=42)
    get_logger("tests").info("processing")
    clear_context()
    get_logger("tests").info("idle")

    first, second = lines(stream)
    assert (first["trace_id"], first["tenant_id"], first["email_id"]) == ("abc123", 7, 42)
    assert "trace_id" not in second


def test_standard_library_loggers_are_also_json(stream: io.StringIO) -> None:
    logging.getLogger("uvicorn.error").info("server started")

    [entry] = lines(stream)
    assert entry["message"] == "server started"
    assert entry["level"] == "INFO"
    assert entry["logger"] == "uvicorn.error"


def test_exceptions_are_rendered_inside_the_json(stream: io.StringIO) -> None:
    try:
        raise ValueError("boom")
    except ValueError:
        get_logger("tests").exception("failed")

    [entry] = lines(stream)
    assert entry["level"] == "ERROR"
    assert "ValueError: boom" in str(entry["exception"])


def test_respects_the_configured_level() -> None:
    buffer = io.StringIO()
    configure_logging("WARNING", stream=buffer)

    get_logger("tests").info("hidden")
    get_logger("tests").error("shown")

    assert [entry["message"] for entry in lines(buffer)] == ["shown"]
