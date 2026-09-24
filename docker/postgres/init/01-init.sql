-- Este script se ejecuta automáticamente SOLO la primera vez que se crea
-- el volumen de datos de Postgres (no en cada arranque del contenedor).
--
-- Úsalo para crear bases de datos o usuarios adicionales aparte de los que
-- ya se crean con las variables POSTGRES_DB / POSTGRES_USER / POSTGRES_PASSWORD
-- del docker-compose.yml.
--
-- Ejemplo (descomenta y ajusta si lo necesitas):
--
-- CREATE DATABASE otra_bd;
-- CREATE USER otro_usuario WITH PASSWORD 'otra_clave';
-- GRANT ALL PRIVILEGES ON DATABASE otra_bd TO otro_usuario;

-- Extensiones útiles habitualmente en proyectos Laravel:
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Necesaria para RAG con embeddings (requiere la imagen pgvector/pgvector,
-- ver el cambio de imagen en docker-compose.yml):
CREATE EXTENSION IF NOT EXISTS vector;

-- Base de datos separada para tests (Pest/PHPUnit), para no mezclar datos
-- de test con los de desarrollo en la misma BD "app":
CREATE DATABASE app_testing OWNER app;

-- Las extensiones son por base de datos, no globales: hay que crearlas
-- también dentro de app_testing (si no, las migraciones que usen columnas
-- vector fallarán solo en el entorno de test).
\c app_testing
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS vector;