"""Conjunto de casos de evaluación (`ai-service/evals/dataset.json`).

Cada caso es el email de un cliente de una empresa de demostración y lo que
se espera de la IA. Lo importante no es acertar el texto exacto sino:

- la intención (`intents`: las aceptables; vacío = cualquiera);
- la **seguridad de la decisión**: `auto_send_ok=False` y la IA recomienda
  enviarlo sola = fallo grave;
- la decisión ideal (`ideal`), para medir también la automatización perdida;
- datos que la respuesta debe dar (`must_include`, cada elemento un texto o
  una lista de alternativas) y que nunca debe dar (`must_not_include`: datos
  de otro cliente, cosas inventadas, el prompt).
"""

import json
from enum import StrEnum
from pathlib import Path
from typing import Annotated, Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator

from ai_service.domain.category import Category


class Tenant(StrEnum):
    SHOP = "shop"  # Empresa Demo: pedidos, facturas, tickets, documentación
    CLINIC = "clinic"  # Clínica Demo: citas


class Ideal(StrEnum):
    AUTO_SEND = "auto_send"
    HUMAN_REVIEW = "human_review"
    EITHER = "either"


class _Model(BaseModel):
    model_config = ConfigDict(extra="forbid", frozen=True)


class Expectation(_Model):
    intents: tuple[Category, ...] = ()
    language: Literal["es", "en"] = "es"
    auto_send_ok: bool
    ideal: Ideal
    must_include: tuple[str | tuple[str, ...], ...] = ()
    must_not_include: tuple[str, ...] = ()
    tools: tuple[str, ...] = ()

    @model_validator(mode="after")
    def _coherent(self) -> "Expectation":
        if self.ideal is Ideal.AUTO_SEND and not self.auto_send_ok:
            raise ValueError("ideal=auto_send con auto_send_ok=false")
        return self


class Case(_Model):
    id: Annotated[str, Field(pattern=r"^[a-z0-9][a-z0-9-]{2,60}$")]
    tenant: Tenant
    sender: Annotated[str, Field(alias="from", pattern=r"^[^@\s]+@[^@\s]+$")]
    subject: str
    body: Annotated[str, Field(min_length=1)]
    expect: Expectation
    tags: tuple[str, ...] = ()


class Dataset(_Model):
    version: Literal[1]
    description: str = ""
    cases: tuple[Case, ...]

    @model_validator(mode="after")
    def _unique_ids(self) -> "Dataset":
        ids = [c.id for c in self.cases]
        if len(ids) != len(set(ids)):
            raise ValueError("ids de caso repetidos")
        return self

    def select(self, only: set[str] | None = None, limit: int | None = None) -> tuple[Case, ...]:
        """Los casos pedidos (`--only`) y como mucho `limit` (`--limit`), en orden."""
        chosen = [c for c in self.cases if only is None or c.id in only]
        return tuple(chosen[:limit] if limit is not None else chosen)


def load_dataset(path: Path) -> Dataset:
    return Dataset.model_validate(json.loads(path.read_text(encoding="utf-8")))
