"""El catálogo de herramientas (`/contracts/tools/catalog.json`) es la fuente
única que comparten Laravel (panel, activación por empresa) y ai-service."""

from pathlib import Path
from typing import Any

from jsonschema import Draft202012Validator
from mcp.client.client import Client

from ai_service.application.services.tool_catalog import DEFAULT_ENABLED_TOOLS, SPECS
from ai_service.infrastructure.tools.mcp_client import READ_ONLY_TOOLS, WRITE_TOOLS
from ai_service.mcp_server.server import build_server
from tests.support.contracts import CONTRACTS, load, registry
from tests.support.mcp import demo_data

TOOLS = Path(CONTRACTS).parent / "tools"


def catalog() -> list[dict[str, Any]]:
    tools: list[dict[str, Any]] = load(TOOLS / "catalog.json")["tools"]
    return tools


def test_the_catalog_matches_its_schema() -> None:
    schema = load(TOOLS / "catalog.schema.json")
    Draft202012Validator(schema, registry=registry()).validate(load(TOOLS / "catalog.json"))


def test_names_are_unique() -> None:
    names = [tool["name"] for tool in catalog()]
    assert len(names) == len(set(names))


def test_ai_service_knows_exactly_the_tools_of_the_catalog() -> None:
    reads = {t["name"] for t in catalog() if t["kind"] == "read"}
    writes = {t["name"] for t in catalog() if t["kind"] == "write"}
    # Lectura: las que el modelo puede pedir; escritura: solo con aprobación.
    assert set(SPECS) == reads
    assert reads - {"search_company_documents"} == set(READ_ONLY_TOOLS)
    assert writes == set(WRITE_TOOLS)


def test_defaults_are_the_same_in_both_sides() -> None:
    assert {t["name"] for t in catalog() if t["default_enabled"]} == DEFAULT_ENABLED_TOOLS


async def test_the_mcp_server_offers_every_data_tool_of_the_catalog() -> None:
    async with Client(build_server(demo_data())) as client:
        served = {t.name for t in (await client.list_tools()).tools}
    assert served == {t["name"] for t in catalog()} - {"search_company_documents"}
