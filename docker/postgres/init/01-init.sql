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
CREATE EXTENSION IF NOT EXISTS vector;