"""`ai-service-tool`: ejecuta una herramienta del servidor MCP a mano, con la
empresa y el cliente que se indiquen (sustituye a `php artisan ai:tool`).

    ai-service-tool --list
    ai-service-tool get_order --tenant 1 --customer cliente@cliente.test \\
        --args '{"order_number": "45821"}'

Pasa por el mismo cliente que usa la IA (`McpToolClient`): solo herramientas
de lectura, el contexto (empresa y cliente) nunca en los argumentos, timeout y
recorte del resultado. Imprime el registro tal cual lo vería la IA.
"""

import argparse
import asyncio
import json
import sys
from dataclasses import asdict
from typing import Any

from ai_service.application.dto import ToolContext
from ai_service.application.services.tool_catalog import SPECS
from ai_service.config import get_settings
from ai_service.domain.email import EmailAddress, TenantId
from ai_service.entrypoints.container import build_tools


def parse(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(prog="ai-service-tool", description=__doc__.splitlines()[0])
    parser.add_argument("tool", nargs="?", help="Nombre de la herramienta (ver --list)")
    parser.add_argument("--list", action="store_true", help="Lista las herramientas de lectura")
    parser.add_argument("--tenant", type=int, help="Id de la empresa")
    parser.add_argument("--customer", help="Email del cliente (el remitente)")
    parser.add_argument("--args", default="{}", help="Argumentos en JSON")
    return parser.parse_args(argv)


async def run(options: argparse.Namespace) -> tuple[int, dict[str, Any] | list[dict[str, str]]]:
    if options.list or not options.tool:
        return 0, [{"name": spec.name, "description": spec.description} for spec in SPECS.values()]
    if options.tenant is None or not options.customer:
        return 2, {"error": "Indica --tenant y --customer."}
    try:
        arguments = json.loads(options.args)
    except json.JSONDecodeError as error:
        return 2, {"error": f"--args no es JSON válido: {error}"}
    tools = build_tools(get_settings())
    if tools is None:
        return 2, {"error": "Falta AI_SERVICE_MCP_URL (servidor MCP)."}
    context = ToolContext(TenantId(options.tenant), EmailAddress(options.customer))
    record = await tools.call(options.tool, arguments, context)
    return (0 if record.status == "success" else 1), asdict(record)


def main(argv: list[str] | None = None) -> None:
    code, output = asyncio.run(run(parse(argv)))
    print(json.dumps(output, ensure_ascii=False, indent=2, default=str))
    sys.exit(code)
