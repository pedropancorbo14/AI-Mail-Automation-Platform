"""Contrato de la API estándar (`contracts/integrations/`): los JSON Schema y los
modelos con los que ai-service valida las respuestas aceptan y rechazan
exactamente los mismos ejemplos (Laravel los valida con el mismo esquema)."""

import re
from pathlib import Path
from typing import Any

import pytest
import yaml  # type: ignore[import-untyped]
from jsonschema import Draft202012Validator, FormatChecker
from pydantic import BaseModel, ValidationError
from referencing import Registry, Resource

from ai_service.mcp_server.integrations.standard_api import (
    AppointmentListPayload,
    AppointmentPayload,
    InvoiceListPayload,
    InvoicePayload,
    OrderListPayload,
    OrderPayload,
)
from tests.support.contracts import CONTRACTS, load

INTEGRATIONS = CONTRACTS.parent / "integrations"
SCHEMAS = INTEGRATIONS / "schemas"
EXAMPLES = INTEGRATIONS / "examples"

MODELS: dict[str, type[BaseModel]] = {
    "order": OrderPayload,
    "invoice": InvoicePayload,
    "order-list": OrderListPayload,
    "invoice-list": InvoiceListPayload,
    "appointment": AppointmentPayload,
    "appointment-list": AppointmentListPayload,
}


def validator(name: str) -> Draft202012Validator:
    schemas = [load(p) for p in SCHEMAS.glob("*.schema.json")]
    registry: Registry[Any] = Registry().with_resources(
        (s["$id"], Resource.from_contents(s)) for s in schemas
    )
    return Draft202012Validator(
        load(SCHEMAS / f"{name}.v1.schema.json"),
        registry=registry,
        format_checker=FormatChecker(),
    )


def examples(kind: str) -> list[Path]:
    return sorted((EXAMPLES / kind).glob("*.json"))


def schema_of(path: Path) -> str:
    return path.stem.split("--")[0]


def test_every_schema_is_valid_and_has_a_model() -> None:
    names = {p.name.removesuffix(".v1.schema.json") for p in SCHEMAS.glob("*.schema.json")}

    assert names == set(MODELS)
    for path in SCHEMAS.glob("*.schema.json"):
        Draft202012Validator.check_schema(load(path))


@pytest.mark.parametrize("path", examples("valid"), ids=lambda p: p.stem)
def test_valid_examples_pass_the_schema_and_the_model(path: Path) -> None:
    data = load(path)

    validator(schema_of(path)).validate(data)
    MODELS[schema_of(path)].model_validate_json(path.read_bytes())


@pytest.mark.parametrize("path", examples("invalid"), ids=lambda p: p.stem)
def test_invalid_examples_are_refused_by_the_schema_and_the_model(path: Path) -> None:
    assert list(validator(schema_of(path)).iter_errors(load(path))), "el esquema lo acepta"
    with pytest.raises(ValidationError):
        MODELS[schema_of(path)].model_validate_json(path.read_bytes())


def test_there_are_examples_of_every_schema() -> None:
    assert {schema_of(p) for p in examples("valid")} == set(MODELS)
    assert {schema_of(p) for p in examples("invalid")} >= {
        "order",
        "invoice",
        "order-list",
        "appointment",
        "appointment-list",
    }


def test_openapi_documents_the_endpoints_with_the_shared_schemas() -> None:
    spec = yaml.safe_load((INTEGRATIONS / "standard-api.v1.openapi.yaml").read_text("utf-8"))
    text = (INTEGRATIONS / "standard-api.v1.openapi.yaml").read_text("utf-8")

    assert set(spec["paths"]) == {
        "/v1/orders/{number}",
        "/v1/orders",
        "/v1/invoices/{number}",
        "/v1/invoices",
        "/v1/appointments/{id}",
        "/v1/appointments",
    }
    refs = set(re.findall(r'"(schemas/[^"]+)"', text))
    assert refs == {f"schemas/{name}.v1.schema.json" for name in MODELS}
    assert all((INTEGRATIONS / ref).exists() for ref in refs)
