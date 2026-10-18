"""Cliente MCP: implementa `ToolPort` y `ActionPort` contra el servidor MCP.

Reglas (las mismas que el ToolExecutor de Laravel, Fase 7):

- **Solo herramientas permitidas.** `call()` solo ejecuta las de lectura
  (`allowed`); las que modifican datos (`actions`) solo por `execute()`, que
  el grafo llama únicamente cuando una persona aprobó la acción, y siempre
  con la clave de idempotencia de la acción (`action_id`).
- **El contexto lo pone el backend**: `tenant_id` y `customer_email` se añaden
  aquí desde `ToolContext`; si los argumentos los traen, se rechaza la llamada.
- **Nunca lanza**: red caída, timeout o error de la herramienta → un
  `ToolCallRecord` con `status` error (el caso irá a una persona).
- Resultado recortado a `max_result_chars` (como Laravel) para no inflar el
  prompt ni los registros.
"""

import asyncio
import json
import time
from collections.abc import Mapping
from typing import Any

import httpx2
from mcp.client.client import Client
from mcp.client.streamable_http import streamable_http_client

from ai_service.application.dto import PendingAction, ToolCallRecord, ToolContext

# Lo pone siempre este cliente, nunca los argumentos.
RESERVED = frozenset({"tenant_id", "customer_email", "idempotency_key"})
READ_ONLY_TOOLS = frozenset(
    {
        "get_customer",
        "get_order",
        "get_customer_orders",
        "get_invoice",
        "get_customer_invoices",
        "get_customer_tickets",
    }
)
WRITE_TOOLS = frozenset({"create_support_ticket"})


class McpToolClient:
    def __init__(
        self,
        url: str,
        token: str,
        *,
        allowed: frozenset[str] = READ_ONLY_TOOLS,
        actions: frozenset[str] = WRITE_TOOLS,
        timeout_seconds: float = 10.0,
        max_result_chars: int = 8000,
    ) -> None:
        self._base = url.rstrip("/")
        self._url = self._base + "/mcp"
        self._token = token
        self._allowed = allowed
        self._actions = actions
        self._timeout = timeout_seconds
        self._max = max_result_chars

    @property
    def available(self) -> frozenset[str]:
        return self._allowed

    @property
    def available_actions(self) -> frozenset[str]:
        return self._actions

    async def availability(self, tenant_id: int) -> dict[str, bool] | None:
        """Qué herramientas tienen datos para la empresa (para el panel). None si
        el servidor MCP no responde: el panel lo muestra como desconocido."""
        try:
            async with httpx2.AsyncClient(
                headers={"Authorization": f"Bearer {self._token}"}, timeout=self._timeout
            ) as http:
                response = await http.get(
                    f"{self._base}/capabilities", params={"tenant_id": tenant_id}
                )
                response.raise_for_status()
                tools = response.json().get("tools", {})
        except Exception:  # red, token, servidor caído
            return None
        return {str(name): bool(value) for name, value in tools.items()}

    async def execute(self, action: PendingAction, context: ToolContext) -> ToolCallRecord:
        """Ejecuta una acción YA APROBADA por una persona (idempotente)."""
        started = time.perf_counter()
        visible = dict(action.arguments)
        if action.tool not in self._actions:
            return self._rejected(action.tool, visible, "Acción no permitida.", started)
        if RESERVED & visible.keys():
            return self._rejected(
                action.tool, visible, "Argumentos reservados (empresa o cliente).", started
            )
        payload = {
            **visible,
            "tenant_id": context.tenant_id.value,
            "customer_email": context.customer.value,
            "idempotency_key": action.action_id,
        }
        return await self._send(action.tool, visible, payload, started)

    async def call(
        self, tool: str, arguments: Mapping[str, Any], context: ToolContext
    ) -> ToolCallRecord:
        started = time.perf_counter()
        visible = dict(arguments)  # lo que se registra: sin el contexto

        if tool not in self._allowed:
            return self._record(
                tool, visible, None, "rejected", "Herramienta no permitida.", started
            )
        if RESERVED & visible.keys():
            return self._record(
                tool,
                visible,
                None,
                "rejected",
                "Argumentos reservados (empresa o cliente).",
                started,
            )

        payload = {
            **visible,
            "tenant_id": context.tenant_id.value,
            "customer_email": context.customer.value,
        }
        return await self._send(tool, visible, payload, started)

    async def _send(
        self, tool: str, visible: dict[str, Any], payload: dict[str, Any], started: float
    ) -> ToolCallRecord:
        try:
            async with asyncio.timeout(self._timeout):
                result = await self._invoke(tool, payload)
        except TimeoutError:
            return self._record(tool, visible, None, "error", f"{tool}: tiempo agotado.", started)
        except Exception as error:  # red, servidor caído, protocolo
            message = f"{tool}: servicio no disponible ({type(error).__name__})."
            return self._record(tool, visible, None, "error", message, started)

        text = "".join(getattr(part, "text", "") for part in result.content)
        if result.is_error:
            # El SDK antepone "Error executing tool X: " al mensaje de la herramienta.
            message = text.removeprefix(f"Error executing tool {tool}: ")
            return self._record(tool, visible, None, "error", message[:500] or "Error.", started)
        return self._record(tool, visible, self._parse(text), "success", None, started)

    async def _invoke(self, tool: str, payload: dict[str, Any]) -> Any:
        http = httpx2.AsyncClient(
            headers={"Authorization": f"Bearer {self._token}"}, timeout=self._timeout
        )
        async with http, Client(streamable_http_client(self._url, http_client=http)) as client:
            return await client.call_tool(tool, payload)

    def _parse(self, text: str) -> dict[str, Any]:
        if len(text) > self._max:
            return {"truncated": True, "partial": text[: self._max]}
        try:
            data = json.loads(text)
        except ValueError:
            return {"text": text}
        return data if isinstance(data, dict) else {"value": data}

    @classmethod
    def _rejected(
        cls, tool: str, arguments: dict[str, Any], reason: str, started: float
    ) -> ToolCallRecord:
        return cls._record(tool, arguments, None, "rejected", reason, started)

    @staticmethod
    def _record(
        tool: str,
        arguments: dict[str, Any],
        result: dict[str, Any] | None,
        status: Any,
        error: str | None,
        started: float,
    ) -> ToolCallRecord:
        return ToolCallRecord(
            tool=tool,
            arguments=arguments,
            result=result,
            status=status,
            error=error,
            duration_ms=int((time.perf_counter() - started) * 1000),
        )
