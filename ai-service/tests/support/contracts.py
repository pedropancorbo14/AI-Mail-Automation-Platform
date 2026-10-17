"""Acceso a los JSON Schema y ejemplos compartidos con Laravel (`/contracts/events/`)."""

import json
import os
from pathlib import Path
from typing import Any

from jsonschema import Draft202012Validator, FormatChecker
from referencing import Registry, Resource

CONTRACTS = Path(
    os.environ.get("AI_SERVICE_CONTRACTS_DIR", Path(__file__).parents[3] / "contracts" / "events")
)


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def example(name: str) -> dict[str, Any]:
    data: dict[str, Any] = load(CONTRACTS / "examples" / "valid" / f"{name}.json")
    return data


def registry() -> Registry[Any]:
    resources = [
        (schema["$id"], Resource.from_contents(schema))
        for schema in (load(p) for p in CONTRACTS.glob("*.schema.json"))
    ]
    return Registry().with_resources(resources)


def validator(event: str) -> Draft202012Validator:
    return Draft202012Validator(
        load(CONTRACTS / f"{event}.schema.json"),
        registry=registry(),
        format_checker=FormatChecker(),
    )


def assert_matches_contract(event: str, payload: dict[str, Any]) -> None:
    validator(event).validate(payload)
