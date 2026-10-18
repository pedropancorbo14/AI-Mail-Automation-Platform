"""Tests de contrato: los modelos Pydantic y los JSON Schema compartidos con
Laravel aceptan y rechazan exactamente lo mismo, con los mismos ejemplos que
usan los tests de Pest (`/contracts/events/examples/`)."""

import json
from pathlib import Path
from typing import Any

import pytest
from jsonschema import Draft202012Validator

from ai_service.application.contracts.events import (
    DocumentDeleted,
    DocumentIndexed,
    DocumentIndexingFailed,
    DocumentUploaded,
    EmailProcessed,
    EmailProcessingFailed,
    EmailReceived,
    EmailReviewResumed,
    InvalidEventError,
    _Envelope,
)
from ai_service.domain.category import Category
from tests.support.contracts import CONTRACTS, load, validator

MODELS: dict[str, type[_Envelope]] = {
    "document.uploaded.v1": DocumentUploaded,
    "document.deleted.v1": DocumentDeleted,
    "document.indexed.v1": DocumentIndexed,
    "document.indexing_failed.v1": DocumentIndexingFailed,
    "email.received.v1": EmailReceived,
    "email.processed.v1": EmailProcessed,
    "email.processing_failed.v1": EmailProcessingFailed,
    "email.review_resumed.v1": EmailReviewResumed,
}


def examples(kind: str) -> list[Any]:
    return [_example(path) for path in sorted((CONTRACTS / "examples" / kind).glob("*.json"))]


def _example(path: Path) -> Any:
    event = path.stem.split("--")[0]
    return pytest.param(event, load(path), id=path.stem)


def test_the_schemas_are_valid_json_schema() -> None:
    schemas = list(CONTRACTS.glob("*.schema.json"))
    assert len(schemas) == 9
    for path in schemas:
        Draft202012Validator.check_schema(load(path))


@pytest.mark.parametrize(("event", "payload"), examples("valid"))
def test_valid_examples_pass_schema_and_model(event: str, payload: dict[str, Any]) -> None:
    validator(event).validate(payload)

    parsed = MODELS[event].parse(payload)

    # Lo que vuelve a serializar este servicio también cumple el esquema.
    validator(event).validate(parsed.to_json_dict())


@pytest.mark.parametrize(("event", "payload"), examples("invalid"))
def test_invalid_examples_are_rejected_by_schema_and_model(
    event: str, payload: dict[str, Any]
) -> None:
    assert not validator(event).is_valid(payload)

    with pytest.raises(InvalidEventError):
        MODELS[event].parse(payload)


def test_every_event_has_valid_and_invalid_examples() -> None:
    for kind in ("valid", "invalid"):
        covered = {p.stem.split("--")[0] for p in (CONTRACTS / "examples" / kind).glob("*.json")}
        assert covered == set(MODELS), kind


def test_categories_match_the_contract() -> None:
    contract = load(CONTRACTS / "common.schema.json")["$defs"]["category"]["enum"]

    assert [c.value for c in Category] == contract


def test_unknown_top_level_fields_are_tolerated() -> None:
    # Lector tolerante: Laravel puede añadir campos opcionales sin romper la v1.
    payload = load(CONTRACTS / "examples" / "valid" / "email.received.v1.json")
    payload["mailbox_id"] = 3

    assert EmailReceived.parse(json.dumps(payload)).email_id == 42


def test_events_created_by_the_service_fill_the_envelope() -> None:
    received = EmailReceived.parse(
        (CONTRACTS / "examples" / "valid" / "email.received.v1.json").read_bytes()
    )
    failed = EmailProcessingFailed(
        trace_id=received.trace_id,
        tenant_id=received.tenant_id,
        email_id=received.email_id,
        error_code="internal",
        message="Error interno al procesar el email.",
        retryable=False,
        usage={"calls": []},  # type: ignore[arg-type]
    )

    data = failed.to_json_dict()
    validator("email.processing_failed.v1").validate(data)
    assert data["event_type"] == "email.processing_failed"
    assert data["occurred_at"].endswith("Z")
    assert failed.event_id != received.event_id


def test_sender_uses_the_from_alias() -> None:
    received = EmailReceived.parse(
        (CONTRACTS / "examples" / "valid" / "email.received.v1.json").read_text()
    )

    assert received.sender == "cliente@cliente.test"
    assert received.to_json_dict()["from"] == "cliente@cliente.test"


def test_malformed_json_is_an_invalid_event() -> None:
    with pytest.raises(InvalidEventError):
        EmailReceived.parse(b"{no es json")
