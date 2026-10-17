-- Acceso de SOLO LECTURA de ai-service a la base de conocimiento (RAG).
--
-- Laravel es el único que escribe en documents y document_chunks (sube,
-- trocea e indexa). ai-service solo busca, con el rol ai_service_reader:
--   * permisos por COLUMNA: solo lo necesario para buscar y citar (ni la
--     ruta del fichero, ni el hash, ni quién lo subió);
--   * Row Level Security: solo ve las filas de la empresa indicada con
--     SET LOCAL app.tenant_id = '<id>'; sin ese valor no ve NADA (falla
--     cerrado). Es una segunda barrera además del filtro por tenant_id de
--     la propia consulta.
-- El dueño de las tablas (el usuario de Laravel) no se ve afectado: sin
-- FORCE ROW LEVEL SECURITY, RLS no se aplica al propietario.
--
-- Lo ejecutan la migración de Laravel (add_knowledge_base_reader) y los
-- tests de integración de ai-service. El rol se crea antes, aparte (lleva
-- contraseña). Idempotente.

GRANT USAGE ON SCHEMA public TO ai_service_reader;

REVOKE ALL ON documents, document_chunks FROM ai_service_reader;
GRANT SELECT (id, tenant_id, title, status, embedding_model) ON documents TO ai_service_reader;
GRANT SELECT (id, tenant_id, document_id, position, content, metadata, embedding) ON document_chunks TO ai_service_reader;

ALTER TABLE documents ENABLE ROW LEVEL SECURITY;
ALTER TABLE document_chunks ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS ai_service_tenant_isolation ON documents;
CREATE POLICY ai_service_tenant_isolation ON documents
    FOR SELECT TO ai_service_reader
    USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint);

DROP POLICY IF EXISTS ai_service_tenant_isolation ON document_chunks;
CREATE POLICY ai_service_tenant_isolation ON document_chunks
    FOR SELECT TO ai_service_reader
    USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint);
