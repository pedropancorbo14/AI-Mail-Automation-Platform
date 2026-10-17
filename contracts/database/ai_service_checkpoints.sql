-- Esquema de los checkpoints de LangGraph de ai-service (Fase 4 del servicio).
--
-- Cuando el agente propone una acción que modifica datos (crear un ticket),
-- el grafo queda en pausa hasta que una persona la apruebe en el panel; su
-- estado se guarda aquí (langgraph-checkpoint-postgres crea las tablas con
-- su propio setup()).
--
-- El rol ai_service_checkpoints solo puede usar este esquema: no recibe
-- ningún permiso sobre las tablas de Laravel (public) y desde PostgreSQL 15
-- PUBLIC ya no puede crear tablas en public.
--
-- Lo ejecutan la migración de Laravel (después de crear el rol) y los tests
-- de integración de ai-service. Idempotente.

CREATE SCHEMA IF NOT EXISTS ai_service AUTHORIZATION ai_service_checkpoints;
ALTER SCHEMA ai_service OWNER TO ai_service_checkpoints;
REVOKE ALL ON SCHEMA ai_service FROM PUBLIC;

-- Sin ALTER ROLE ... SET search_path: el rol es del clúster y varias
-- migraciones a la vez (los tests en paralelo) chocan al cambiarlo ("tuple
-- concurrently updated"). ai-service fija search_path=ai_service al conectar.
