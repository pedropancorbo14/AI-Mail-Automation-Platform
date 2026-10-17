# Contrato de base de datos con ai-service

ai-service no escribe en las tablas de Laravel. La única excepción a "cada
servicio con sus datos" es la **lectura de la base de conocimiento (RAG)**:
los vectores ya están en PostgreSQL (`document_chunks`, pgvector) y
copiarlos a otra base de datos no aportaría nada.

- [`knowledge_base_reader.sql`](knowledge_base_reader.sql): permisos del rol
  `ai_service_reader` (solo `SELECT`, por columna) y **RLS por empresa**.
  Lo ejecutan la migración de Laravel y los tests de ai-service.
- Columnas que lee ai-service (si una migración de Laravel las cambia, los
  tests de los dos lados fallan):

| Tabla | Columnas |
|---|---|
| `documents` | `id`, `tenant_id`, `title`, `status`, `embedding_model` |
| `document_chunks` | `id`, `tenant_id`, `document_id`, `position`, `content`, `metadata`, `embedding` (`vector(1024)`) |

Cómo consulta ai-service: dentro de una transacción,
`SET LOCAL app.tenant_id = '<id>'` (RLS) **y** `WHERE tenant_id = :id` en
la consulta; solo documentos `ready` con `embedding_model` igual al suyo.
