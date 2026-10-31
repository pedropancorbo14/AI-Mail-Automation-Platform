-- Producción (Fase 16): solo las extensiones, en la base de datos de la app
-- (POSTGRES_DB). Sin la base de datos de tests de docker/postgres/init.
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS vector;
