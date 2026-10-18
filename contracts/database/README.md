# Contrato de base de datos con ai-service

ai-service **no lee ni escribe las tablas de Laravel**: todo lo que necesita
le llega en los eventos (`contracts/events/`) o por la API interna. Tiene su
propio esquema `ai_service` en la misma base de datos, con su propio rol:

- [`ai_service_checkpoints.sql`](ai_service_checkpoints.sql): esquema
  `ai_service` propiedad del rol `ai_service_checkpoints` (sin acceso a las
  tablas de Laravel). Ahí guarda ai-service los checkpoints de LangGraph
  (acciones que esperan aprobación) y la base de conocimiento
  (`knowledge_chunks`: fragmentos con sus embeddings, pgvector). Lo ejecutan
  la migración de Laravel (`add_ai_service_checkpoints`), el overlay local de
  Kubernetes y los tests de ai-service.

Cómo busca ai-service: siempre con `WHERE tenant_id = :id` (no existe una
búsqueda "de todas las empresas"), solo con fragmentos de su mismo modelo de
embeddings.
