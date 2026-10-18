"""Prueba de humo de ai-service desplegado en Kubernetes.

Hace de Laravel: publica un email.received.v1 en Redis Streams y espera el
email.processed.v1 que responde a él (causation_id = event_id). Comprueba que
el worker lo procesó entero, con el servidor MCP incluido: Laura pregunta por
su pedido sin dar el número, el plan consulta su lista de pedidos y la IA
(simulada en local) pide el detalle del más reciente antes de redactar.

Usa redis-py, que ya va en la imagen de ai-service. Sale con código 0 si todo
cuadra y 1 si no (el Job queda en Failed y se ve en `kubectl get jobs`).
"""

import json
import os
import sys
import time
import uuid
from datetime import UTC, datetime

import redis

TIMEOUT_SECONDS = int(os.environ.get("SMOKE_TIMEOUT_SECONDS", "120"))


def now() -> str:
    return datetime.now(UTC).isoformat().replace("+00:00", "Z")


def connect() -> redis.Redis:
    """Redis puede rechazar los primeros segundos: con NetworkPolicies, el
    controlador de red tarda un momento en dar de alta la IP de un pod nuevo."""
    client = redis.Redis.from_url(os.environ["AI_SERVICE_REDIS_URL"], decode_responses=True)
    for _ in range(30):
        try:
            client.ping()
            return client
        except redis.ConnectionError:
            time.sleep(1)
    client.ping()  # el último intento lanza el error real
    return client


def main() -> int:
    client = connect()
    event_id = str(uuid.uuid4())
    event = {
        "event_id": event_id,
        "event_type": "email.received",
        "event_version": 1,
        "occurred_at": now(),
        "trace_id": f"smoke-{event_id[:8]}",
        "tenant_id": 1,
        "email_id": 900_000 + int(time.time()) % 100_000,
        "from": "laura@cliente.test",
        "subject": "Mi pedido",
        "body": "Hola, ¿cuándo llega mi pedido? Gracias, Laura",
        "received_at": now(),
        # La empresa tiene activadas las herramientas de pedidos (las decide Laravel).
        "enabled_tools": ["get_customer_orders", "get_order"],
    }
    # Leer desde el último resultado que ya existe: solo cuenta el nuestro.
    last = client.xrevrange("email.processed.v1", count=1)
    cursor = last[0][0] if last else "0-0"
    client.xadd(
        "email.received.v1",
        {"event_id": event_id, "event_type": "email.received", "payload": json.dumps(event)},
    )
    print(f"publicado email.received.v1 {event_id}")

    deadline = time.monotonic() + TIMEOUT_SECONDS
    while time.monotonic() < deadline:
        for _, entries in client.xread({"email.processed.v1": cursor}, block=2000) or []:
            for entry_id, fields in entries:
                cursor = entry_id
                result = json.loads(fields["payload"])
                if result.get("causation_id") == event_id:
                    return check(result)
    print(f"FALLO: sin email.processed.v1 en {TIMEOUT_SECONDS} s")
    return 1


def check(result: dict) -> int:
    tools = [(c["tool"], c.get("origin"), c["status"]) for c in result.get("tool_calls", [])]
    print(
        json.dumps(
            {
                "decision": result["decision"],
                "category": result["category"],
                "specialists": result.get("specialists"),
                "tool_calls": tools,
                "sources": result.get("sources"),
            },
            ensure_ascii=False,
        )
    )
    problems = []
    if result.get("tenant_id") != 1:
        problems.append("el resultado es de otra empresa")
    if result["decision"] != "auto_send":
        problems.append(f"decisión {result['decision']}: {result.get('reasons')}")
    if ("get_customer_orders", "plan", "success") not in tools:
        problems.append("no consultó la lista de pedidos (¿llega a ai-mcp?)")
    if ("get_order", "model", "success") not in tools:
        problems.append("la IA no pidió el detalle del pedido")
    for problem in problems:
        print(f"FALLO: {problem}")
    if not problems:
        print("OK: ai-service y ai-mcp responden de punta a punta")
    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
