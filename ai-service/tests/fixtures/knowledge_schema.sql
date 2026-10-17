-- Las columnas de documents y document_chunks que lee ai-service (las crea
-- Laravel en sus migraciones; contracts/database/README.md). Solo para los
-- tests de ai-service: el test de Laravel KnowledgeBaseReaderTest comprueba
-- que el esquema real las tiene.
CREATE EXTENSION IF NOT EXISTS vector;
DROP TABLE IF EXISTS document_chunks;
DROP TABLE IF EXISTS documents;
CREATE TABLE documents (
    id bigserial PRIMARY KEY,
    tenant_id bigint NOT NULL,
    title varchar(255) NOT NULL,
    path varchar(255) NOT NULL DEFAULT 'secreto/ruta',
    status varchar(20) NOT NULL DEFAULT 'pending',
    embedding_model varchar(100)
);
CREATE TABLE document_chunks (
    id bigserial PRIMARY KEY,
    tenant_id bigint NOT NULL,
    document_id bigint NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    position integer NOT NULL,
    content text NOT NULL,
    metadata jsonb,
    embedding vector(1024) NOT NULL
);
